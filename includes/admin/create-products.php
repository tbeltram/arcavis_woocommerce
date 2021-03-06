<?php
// Setting a custom timeout value for cURL. Using a high value for priority to ensure the function runs after any other added to the same action hook.
add_action('http_api_curl', 'sar_custom_curl_timeout', 9999, 1);
function sar_custom_curl_timeout( $handle ){
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 30 ); // 30 seconds. Too much for production, only for testing.
	curl_setopt( $handle, CURLOPT_TIMEOUT, 30 ); // 30 seconds. Too much for production, only for testing.
}
// Setting custom timeout for the HTTP request
add_filter( 'http_request_timeout', 'sar_custom_http_request_timeout', 9999 );
function sar_custom_http_request_timeout( $timeout_value ) {
	return 30; // 30 seconds. Too much for production, only for testing.
}
// Setting custom timeout in HTTP request args
add_filter('http_request_args', 'sar_custom_http_request_args', 9999, 1);
function sar_custom_http_request_args( $r ){
	$r['timeout'] = 30; // 30 seconds. Too much for production, only for testing.
	return $r;
}
/*This Class Has functions for Syncronize the products from arcavis.*/
set_time_limit(0);
require_once(WP_PLUGIN_DIR  . '/woocommerce/includes/libraries/wp-async-request.php');
require_once(WP_PLUGIN_DIR  . '/woocommerce/includes/libraries/wp-background-process.php');
class WooCommerce_Arcavis_Create_Products_Settings{
	private $_background_process;

	public function __construct(){
		$this->_background_process = new Update_Images_Process();
	}

	##This function is used for running first time sysc or re-sync.
	public function create_products_init(){			
		global $wc_arcavis_shop;
		global $wpdb;
		try
		{
			if(isset($_POST['delete_or_not']) && $_POST['delete_or_not'] == 'yes'){
				$this->delete_all_data();
			}
			
			$options = $wc_arcavis_shop->settings_obj->get_arcavis_settings();
			$first_sync = get_option('arcavis_first_sync');
			if($first_sync == 'completed'){
				echo "exit";
				exit;
			}
			if(isset($options['arcavis_link']) && $options['arcavis_link'] == ''){
				echo "exit";
				exit;
			}
			$lastSync = $wc_arcavis_shop->get_last_sync('articles');
			if($lastSync == ''){
				$lastSyncPage = $wc_arcavis_shop->get_last_page();			
				$url = $options['arcavis_link'].'/api/articles?mainArticleId=0&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true&pageSize=50&page='.$lastSyncPage;
				$request_args = array(
				  'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] )
				  )
				);

				$product_data = wp_remote_get($url,$request_args);
				if(is_wp_error($product_data)){
					$wc_arcavis_shop->logError('wp_remote_get failed '.$product_data->geterrormessage());
					exit;
				}
				$products = json_decode($product_data['body']);
				if(!empty($products)){
					
					foreach ($products->Result as $product) {
							$wc_arcavis_shop->logDebug('Inserting Product '.$product->Title);
							if($product->Status == '0'){
								$Status = 'publish';
							}elseif($product->Status == '1'){
								$Status = 'trash';
							}elseif($product->Status == '2'){
								$Status = 'trash';
							}
							if(isset($product->Description)){
								$description = $product->Description;
							}else{
								$description = '';
							}
							$_data = array(
								'post_author'  => 1,
								'post_title' => trim(str_replace('  ',' ', str_replace('&','&amp;',$product->Title))),
								'post_content' => $description,
								'post_status' => $Status,
								'post_type' => 'product'
							);
							$post_id = wp_insert_post($_data);
							if($product->SalePrice == 0){
								$SalePrice = '';
								update_post_meta( $post_id, '_price', $product->Price );
							}else{
								$SalePrice = $product->SalePrice;
								update_post_meta( $post_id, '_price',$SalePrice );
							}
							
							update_post_meta($post_id, '_sku', $product->ArticleNumber );
							update_post_meta( $post_id,'article_id',$product->Id);
							update_post_meta( $post_id, '_regular_price', $product->Price );
							update_post_meta( $post_id, '_sale_price', $SalePrice );				
							update_post_meta( $post_id,'_visibility','visible');
							wp_set_object_terms($post_id, 'simple', 'product_type');
							// Stock
							$stockstatus='instock';
							if($product->Stock<=0){
								$stockstatus='outofstock';
							}
							update_post_meta($post_id, '_manage_stock', 'yes' );
							update_post_meta( $post_id, '_stock_status', $stockstatus);	
							update_post_meta( $post_id,'_stock',$product->Stock);

							if(!empty($product->Images))
							{
								$this->update_images($product->Images,$post_id);
							}

							// Add Categories
							$categories = $this->add_category($product->MainGroupTitle,$product->TradeGroupTitle,$product->ArticleGroupTitle);
							$categories = array_map( 'intval', $categories );
							wp_set_object_terms( $post_id, $categories, 'product_cat' );
							
							// Unlink Tags
							wp_delete_object_term_relationships( $post_id, 'product_tag' );
							// Add Tags
							if(array_key_exists("Tags", $product) && !empty($product->Tags)){
								$tags = $this->add_tags($product->Tags);
								$tags = array_map( 'intval', $tags);
								wp_set_object_terms( $post_id, $tags, 'product_tag' );
							}
							
							// Add Variations
							if(array_key_exists("HasVariations", $product)){
								if($product->HasVariations == 'true'){
									wp_set_object_terms($post_id, 'variable', 'product_type');
									update_post_meta($post_id, '_manage_stock', 'no' );	
									$variation_url = $options['arcavis_link'].'/api/articles?mainArticleId='.$product->Id.'&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true';
									$variation_data = wp_remote_get($variation_url,$request_args);
									if(is_wp_error($variation_data)){
										$wc_arcavis_shop->logError('wp_remote_get failed '.$variation_data->geterrormessage());
										exit;
									}
									$variations = json_decode($variation_data['body']);
									if(!empty($variations)){
										foreach ($variations->Result as $key => $variation) {
											$this->insert_product_attributes($post_id, $variation->Attributes);
											$this->create_product_variation($post_id, $variation);
										}
										$this->insert_variations_default_attributes($post_id,$variations->Result[0]->Attributes);
									}
								}
								   
							}// End of add variations
					}// End of foreach loop
					$this->_background_process->save()->dispatch();
					if($products->TotalPages <= $lastSyncPage){
						$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync' => $products->DataAgeTicks), array('apiName'=>'articles'));
						$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync' => $products->DataAgeTicks), array('apiName'=>'articlestocks'));			
						update_option('arcavis_first_sync','completed');
						echo "exit";
					}else{

						$nextpage = $lastSyncPage+1;
						$table_name2 = $wpdb->prefix .'lastSyncPage';
						$wpdb->insert($table_name2, array( 'lastPage' => $nextpage));
						
						echo "continue";
					}
					
					$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync'=>$products->DataAgeTicks), array('apiName'=>'articles'));
					wc_update_product_lookup_tables();
					wc_update_product_lookup_tables_column("onsale");
					wc_delete_product_transients();
					delete_transient('wc_products_onsale');
				}
				
			}
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('create_products_init '.$e->getMessage());
			echo $e->getMessage();
			exit;
		}
	}

	##This function Delete all Arcavis data from woocommerce including orders.
	public function delete_all_data(){
		global $wpdb;

		$products = $wpdb->get_results("SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation')");
		if(!empty($products)){
			foreach ($products as $product) {				
				wp_delete_attachment( $product->ID, true );
				$attachments = get_attached_media( '', $product->ID );
				foreach ($attachments as $attachment) {
				  wp_delete_attachment( $attachment->ID, 'true' );
				}
			}
		}
			
		$wpdb->query("DELETE a,c FROM ".$wpdb->prefix."terms AS a 
		              LEFT JOIN ".$wpdb->prefix."term_taxonomy AS c ON a.term_id = c.term_id
		              LEFT JOIN ".$wpdb->prefix."term_relationships AS b ON b.term_taxonomy_id = c.term_taxonomy_id
		              WHERE c.taxonomy = 'product_tag'");
		$wpdb->query("DELETE a,c FROM ".$wpdb->prefix."terms AS a
		              LEFT JOIN ".$wpdb->prefix."term_taxonomy AS c ON a.term_id = c.term_id
		              LEFT JOIN ".$wpdb->prefix."term_relationships AS b ON b.term_taxonomy_id = c.term_taxonomy_id
		              WHERE c.taxonomy = 'product_cat'");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."terms WHERE term_id IN (SELECT term_id FROM ".$wpdb->prefix."term_taxonomy WHERE taxonomy LIKE 'pa_%')");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."term_taxonomy WHERE taxonomy LIKE 'pa_%'");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."term_relationships WHERE term_taxonomy_id not IN (SELECT term_taxonomy_id FROM ".$wpdb->prefix."term_taxonomy)");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."term_relationships WHERE object_id IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation'))");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."postmeta WHERE post_id IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation','shop_coupon'))");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation','shop_coupon','shop_order')");
		$wpdb->query("DELETE FROM ".$wpdb->prefix."woocommerce_order_itemmeta");		
		$wpdb->query("DELETE FROM ".$wpdb->prefix."woocommerce_order_items");
		$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."arcavis_logs");
		$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix ."lastSyncTicks");
		$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix ."lastSyncPage");

		$wpdb->insert($wpdb->prefix ."lastSyncTicks", array( 'apiName' => 'articles', 'lastSync' => '' , 'updated' => '' ));
		$wpdb->insert($wpdb->prefix ."lastSyncTicks", array( 'apiName' => 'articlestocks', 'lastSync' => '' , 'updated' => ''));
		$wpdb->insert($wpdb->prefix ."lastSyncPage", array( 'lastPage' => '1'));

		update_option('arcavis_first_sync','');

	}

	/**
	 *
	 * This function is used updating products in given time interval.
	 *
	 * @param    
	 * @return   
	 *
	 */
	public function update_products(){

		global $wc_arcavis_shop;
		global $wpdb;
		
		try{
			$options = $wc_arcavis_shop->settings_obj->get_arcavis_settings();
			
			// No Url set
			if(isset($options['arcavis_link']) && $options['arcavis_link'] == ''){
				return;
			}
			
			$lastSync = $wc_arcavis_shop->get_last_sync('articles');
			if($lastSync != ''){	
				$url = $options['arcavis_link'].'/api/articles?mainArticleId=0&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true&changedSinceTicks='.$lastSync;

				$request_args = array(
				  'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] )
				  )
				);
				
				$product_data = wp_remote_get($url,$request_args);
				if(is_wp_error($product_data)){
					$wc_arcavis_shop->logError('wp_remote_get failed '.$product_data->geterrormessage());
					exit;
				}
				$products = json_decode($product_data['body']);
				
				if(isset($products->DeletedIds) && !empty($products->DeletedIds)){
					$args = array(
					'post_type'		=>	array('product','product_variation'),
					'meta_query' => array(
							array(
								'key' => 'article_id',
								'value'     => $products->DeletedIds,
								'compare'   => 'IN'
							),
						)
					);
					$products_by_article_id = get_posts($args);
					
					$product_string = ''; 
					if(!empty($products_by_article_id)){
						
						foreach ($products_by_article_id as $p_id) {
							$product_string .= "'".$p_id->ID."',";
							wp_delete_attachment( $p_id->ID, true );
						}		
						
						$wpdb->query("DELETE FROM ".$wpdb->prefix."postmeta WHERE post_id IN (".rtrim($product_string,',').")");
						$wpdb->query("DELETE FROM ".$wpdb->prefix."posts WHERE ID IN (".rtrim($product_string,',').")");
						$wpdb->query("DELETE FROM ".$wpdb->prefix."term_relationships WHERE object_id IN (".rtrim($product_string,',').")");
						$wpdb->query("UPDATE ".$wpdb->prefix."term_taxonomy tt SET count = (SELECT count(p.ID) FROM ".$wpdb->prefix."term_relationships tr LEFT JOIN ".$wpdb->prefix."posts p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.post_type IN ('product','product_variation') )");
					}
				}
				if(!empty($products->Result)){
					// Loop through all updated products
					foreach ($products->Result as $product) {
						$wc_arcavis_shop->logDebug('Update Product '.$product->Title);
						$check_product = $this->check_product_existance($product->ArticleNumber);

						// Status
						if($product->Status == '0'){
							$Status = 'publish';
						}elseif($product->Status == '1'){
							$Status = 'trash';
						}elseif($product->Status == '2'){
							$Status = 'trash';
						}
						// Description
						if(isset($product->Description)){
							$description = $product->Description;
						}else{
							$description = '';
						}
						// Title
						$title=trim(str_replace('  ',' ', str_replace('&','&amp;',$product->Title)));
						// Create or update
						if($check_product != ''){
							// existing product
							$post_id = $check_product;
							$product_data = array(
								'ID'           => $post_id,
								'post_title' => $title,
								'post_content' => $description,
								'post_type' => 'product',
								'post_status' => $Status
							);
							wp_update_post( $product_data );
						}else{
							// new product
							$_data = array(
								'post_author'  => 1,
								'post_title' => $title,
								'post_content' => $description,
								'post_status' => $Status,
								'post_type' => 'product'
							);
							$post_id = wp_insert_post($_data);
							// Stock
							$stockstatus='instock';
							if($product->Stock<=0){
								$stockstatus='outofstock';
							}
							update_post_meta($post_id, '_manage_stock', 'yes' );
							update_post_meta($post_id, '_stock_status', $stockstatus);	
							update_post_meta($post_id,'_stock',$product->Stock);
							wp_set_object_terms($post_id, 'simple', 'product_type');
						}
						if($product->SalePrice == 0){
							$SalePrice = '';
							update_post_meta($post_id, '_price', $product->Price );
						}else{
							$SalePrice = $product->SalePrice;
							update_post_meta($post_id, '_price', $SalePrice);
						}
						
						update_post_meta($post_id, '_sku', $product->ArticleNumber );
						update_post_meta($post_id,'article_id',$product->Id);
						update_post_meta($post_id, '_regular_price', $product->Price );
						update_post_meta($post_id, '_sale_price', $SalePrice );			
						update_post_meta($post_id,'_visibility','visible');	
						
						
						$attachments = get_attached_media( '', $post_id );
						foreach ($attachments as $attachment) {
						  wp_delete_attachment( $attachment->ID, 'true' );
						}

						if(!empty($product->Images))
						{
							$this->update_images($product->Images,$post_id);
						}
						// Set categories
						$categories = $this->add_category($product->MainGroupTitle,$product->TradeGroupTitle,$product->ArticleGroupTitle);
						$categories = array_map( 'intval', $categories );
						wp_set_object_terms( $post_id, $categories, 'product_cat' );
						
						// Unlink Tags
						wp_delete_object_term_relationships( $post_id, 'product_tag' );
						// Add Tags
						if(array_key_exists("Tags", $product) && !empty($product->Tags)){
							$tags = $this->add_tags($product->Tags);
							$tags = array_map( 'intval', $tags);
							wp_set_object_terms($post_id, $tags, 'product_tag' );
						}

						// Update variations
						if(array_key_exists("HasVariations", $product)){
							if($product->HasVariations == 'true'){
								wp_set_object_terms($post_id, 'variable', 'product_type');
								update_post_meta($post_id, '_manage_stock', 'no' );
								update_post_meta($post_id,'_stock',0);
								$variation_url = $options['arcavis_link'].'/api/articles?mainArticleId='.$product->Id.'&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true';
								$variation_data = wp_remote_get($variation_url,$request_args);
								if(is_wp_error($variation_data)){
									$wc_arcavis_shop->logError('wp_remote_get failed '.$variation_data->geterrormessage());
									exit;
								}
								$variations = json_decode($variation_data['body']);
								if(!empty($variations)){
									foreach ($variations->Result as $key => $variation) {
										$this->insert_product_attributes($post_id, $variation->Attributes);
										$this->create_product_variation($post_id, $variation);
									}
									$this->insert_variations_default_attributes($post_id,$variations->Result[0]->Attributes);
								}
							}
							   
						}
						
					}// End of foreach loop
					$this->_background_process->save()->dispatch();
					$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync'=>$products->DataAgeTicks), array('apiName'=>'articles'));
					wc_update_product_lookup_tables();
					wc_update_product_lookup_tables_column("onsale");
					wc_delete_product_transients();
					delete_transient('wc_products_onsale');
				}//end of checking products are empty or not.

				$this->update_article_stock($options);
			}
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('update_products '.$e->getMessage());
		}
	}

	##This function is used to update the articles stock by article ID.
	public function update_article_stock_by_id($options,$article_id){
		$url = $options['arcavis_link'].'/api/articlestocks/'.$article_id;
		$request_args = array(
		  'headers' => array(
			'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] )
		  )
		);
		$stock_data = wp_remote_get($url,$request_args);
		if(is_wp_error($stock_data)){
			$wc_arcavis_shop->logError('wp_remote_get failed '.$stock_data->geterrormessage());
			exit;
		}
		$stocks = json_decode(wp_remote_retrieve_body($stock_data));
		if(!empty($stocks->Result)){
			$total_stock = 0;
			foreach ($stocks->Result as $stock) {
				$total_stock += $stock->Stock;
			}
			$args = array(
			'post_type'		=>	array('product','product_variation'),
			'meta_query' => array(
					array(
						'key' => 'article_id',
						'value'     => $article_id,
						'compare'   => '='
					),
				)
			);
			$products = get_posts($args);
			if(!empty($products)){
				$stockstatus='instock';
				if($total_stock<=0){
					$stockstatus='outofstock';
				}
				update_post_meta($products[0]->ID, '_stock_status', $stockstatus );
				
				update_post_meta($products[0]->ID,'_stock',$total_stock);
			}
		}
	}

	/**
	 *
	 * This functions calls the api for changed stocks and updates the articles stocks accordingly
	 *
	 * @param    The arcavis sync-settings
	 * @return   
	 *
	 */
	public function update_article_stock($options){
		global $wc_arcavis_shop;
		global $wpdb;

		$lastSync = $wc_arcavis_shop->get_last_sync('articlestocks');
		$url = $options['arcavis_link'].'/api/articlestocks?groupByArticle=true&changedSinceTicks='.$lastSync;
		$request_args = array(
		  'headers' => array(
			'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] )
		  )
		);
		$stock_data = wp_remote_get($url,$request_args);
		if(is_wp_error($stock_data)){
			$wc_arcavis_shop->logError('wp_remote_get failed '.$stock_data->geterrormessage());
			exit;
		}
		$stocks = json_decode(wp_remote_retrieve_body($stock_data));
		if(!empty($stocks)){
			foreach ($stocks->Result as $stock) {
				$args = array(
				'post_type'		=>	array('product','product_variation'),
				'meta_query' => array(
						array(
							'key' => 'article_id',
							'value'     => $stock->ArticleId,
							'compare'   => '='
						),
					)
				);
				$products = get_posts($args);
				if(!empty($products)){
					$stockstatus='instock';
					if($stock->Stock<=0){
						$stockstatus='outofstock';
					}
					update_post_meta($products[0]->ID, '_stock_status', $stockstatus );
					update_post_meta( $products[0]->ID,'_stock',$stock->Stock);
				}
			}
			$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync' => $stocks->DataAgeTicks), array('apiName'=>'articlestocks'));
		}
	}
	
	##This function is used create Attributes
	public function create_attribute($attribute_name) {
	    global $wpdb;
		$return = array();    
		try{
			// Create attribute
			$attribute = array(
			  'attribute_label'   => $attribute_name,
			  'attribute_name'    => str_replace(" ","-",strtolower($attribute_name)),
			  'attribute_type'    => 'select',
			  'attribute_orderby' => 'menu_order',
			  'attribute_public'  => 0,
			);
			$check_existance = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix . "woocommerce_attribute_taxonomies WHERE attribute_name ='".str_replace(" ","-",strtolower($attribute_name))."'");
			if(empty($check_existance)){
				$wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute );
			}
			$return['attribute_slug'] = str_replace(" ","-",strtolower($attribute_name));

			// Register the taxonomy
			$name  = wc_attribute_taxonomy_name( $attribute_name );
			$label = $attribute_name;
			
			delete_transient( 'wc_attribute_taxonomies' );
			clean_taxonomy_cache( $attroibute_name );
			global $wc_product_attributes;
			$wc_product_attributes = array();

			foreach ( wc_get_attribute_taxonomies() as $tax ) {
				if ( $name = wc_attribute_taxonomy_name( $tax->attribute_name ) ) {
					$wc_product_attributes[ $name ] = $tax;
				}
			}

			return $return;
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('create_attribute '.$e->getMessage());
			return $return;
			
		}
	}


	public function insert_product_attributes ($post_id, $variation){
		$product_attributes = array();
		// wp_set_object_terms(23, array('small', 'medium', 'large'), 'pa_size');
		foreach ($variation as $attr) {
			// Ignore Season
			if($attr->Name!="Season"){
			
				$data = $this->create_attribute($attr->Name);

				$product_attributes['pa_'.str_replace(" ","-",strtolower($attr->Name))] = array(
							'name'=>'pa_'.str_replace(" ","-",strtolower($attr->Name)),
							'value'=> '',
							'is_visible'   => '1',
							'is_variation' => '1',
							'is_taxonomy'  => '1'
							 );
			}
		}
		update_post_meta( $post_id,'_product_attributes',$product_attributes);
	}

	function create_product_variation( $product_id, $variation_data ){
		// Get the Variable product object (parent)
		$product = wc_get_product($product_id);

		$check_product = $this->check_product_existance($variation_data->ArticleNumber);

		if($variation_data->Status == '0'){
			$Status = 'publish';
		}elseif($variation_data->Status == '1'){
			$Status = 'trash';
		}elseif($variation_data->Status == '2'){
			$Status = 'trash';
		}

		if($check_product != ''){
			$variation_id = $check_product;
			$variation_post = array( // Setup the post data for the variation
				'ID'          => $variation_id,
				'post_title'  => $product->get_title(),
				'post_name'   => 'product-'.$product_id.'-variation',
				'post_status' => $Status,
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'guid'        => $product->get_permalink()
			);
			wp_update_post( $variation_post );
		}else{
			$variation_post = array( // Setup the post data for the variation

				'post_title'  => $product->get_title(),
				'post_name'   => 'product-'.$product_id.'-variation',
				'post_status' => $Status,
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'guid'        => $product->get_permalink()
			);
			$variation_id = wp_insert_post($variation_post); // Insert the variation
		}
		// Get an instance of the WC_Product_Variation object
		$variation = new WC_Product_Variation( $variation_id );

		// Iterating through the variations attributes
		foreach ($variation_data->Attributes as $attr){
			if($attr->Name == "Season"){
				continue;
			}
			$attribute = $attr->Name;
			$term_name = $attr->Value;
			$taxonomy = 'pa_'.str_replace(" ","-",strtolower($attr->Name)); // The attribute taxonomy

			if( ! taxonomy_exists( $taxonomy ) ){
				register_taxonomy(
					$taxonomy,
				'product',
					array(
						'hierarchical' => false,
						'label' => ucfirst( $attribute ),
						'query_var' => true,
						'rewrite' => array( 'slug' => sanitize_title($attribute) ), // The base slug
					)
				);
			}

			// Check if the Term name exist and if not we create it.
			if( ! term_exists( $term_name, $taxonomy ) )
				wp_insert_term( $term_name, $taxonomy ); // Create the term

			$term_slug = get_term_by('name', $term_name, $taxonomy )->slug; // Get the term slug

			// Get the post Terms names from the parent variable product.
			$post_term_names =  wp_get_post_terms( $product_id, $taxonomy, array('fields' => 'names') );

			// Check if the post term exist and if not we set it in the parent variable product.
			if( ! in_array( $term_name, $post_term_names ) )
				wp_set_post_terms( $product_id, $term_name, $taxonomy, true );

			// Set/save the attribute data in the product variation
			update_post_meta( $variation_id, 'attribute_'.$taxonomy, $term_slug );

		}

		## Set/save all other data 
		// SKU
		if( ! empty( $variation_data->ArticleNumber) )
			$variation->set_sku( $variation_data->ArticleNumber );

		// Prices
		if( empty( $variation_data->SalePrice ) ){
			$variation->set_price( $variation_data->Price);
		} else {
			$variation->set_price( $variation_data->SalePrice );
			$variation->set_sale_price( $variation_data->SalePrice );
		}
		$variation->set_regular_price( $variation_data->Price );

		// Stock
		$variation->set_manage_stock(true);
		$variation->set_stock_quantity( $variation_data->Stock );
		$variation->set_stock_status('');
		$variation->set_weight(''); // weight (reseting)
		$variation->save(); // Save the data

		//Article Id (for mapping ordered articles back to arcavis)
		update_post_meta($variation_id,'article_id',$variation_data->Id);
	}

	function insert_variations_default_attributes( $post_id, $products_data ){
		foreach( $products_data as $attribute => $value )
			$variations_default_attributes['pa_'.$attribute] = get_term_by( 'name', $value, 'pa_'.$attribute )->slug;
		// Save the variation default attributes to variable product meta data
		update_post_meta( $post_id, '_default_attributes', $variations_default_attributes );
	}

	##This function is used to assign categories to articles
	public function add_category($category1,$category2,$category3){
		$return = array();
		global $wc_arcavis_shop;
		try{
			$category1=trim(str_replace('  ',' ', str_replace('&','&amp;',$category1)));
			$category2=trim(str_replace('  ',' ', str_replace('&','&amp;',$category2)));
			$category3=trim(str_replace('  ',' ', str_replace('&','&amp;',$category3)));
			
			$category1_exist = term_exists( $category1, 'product_cat' );
			if($category1_exist){

				$return[] = $category1_exist['term_id'];
				$parent_term_id = $category1_exist['term_id'];
			}else{

				$category1_array = wp_insert_term(
					$category1, // the term 
					'product_cat', // the taxonomy
					array(		        
						'slug' => strtolower(str_replace(" ", "-", $category1)),		        
					)
				);

				$return[] = $category1_array['term_id'];
				$parent_term_id = $category1_array['term_id'];
			}
			

			if($category2 != ''){
				$category2_exist = term_exists( $category2, 'product_cat' );
				if($category2_exist){

					$return[] = $category2_exist['term_id'];
					$parent_term_id2 = $category2_exist['term_id'];

				}else{
					$category2_array = wp_insert_term(
						$category2, // the term 
						'product_cat', // the taxonomy
						array(
							'slug' => strtolower(str_replace(" ", "-", $category2)),
							'parent'=> $parent_term_id
						)
					);

					$return[] = $category2_array['term_id'];
					$parent_term_id2 = $category2_array['term_id'];
				}
			}
			if($category3 != ''){
				$category3_exist = term_exists( $category3, 'product_cat' );
				if($category3_exist){

					$return[] = $category3_exist['term_id'];

				}else{
					$category3_array = wp_insert_term(
						$category3, // the term 
						'product_cat', // the taxonomy
						array(
							'slug' => strtolower(str_replace(" ", "-", $category3)),
							'parent'=> $parent_term_id2
						)
					);
					$return[] = $category3_array['term_id'];
				}
			}

			return $return;
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('add_category '.$e->getMessage());		
			return $return;
		}
	}
	
	public function add_tags(array $tags){
		$return = array();
		global $wc_arcavis_shop;
		try{
			foreach ($tags as $tag) {	
				$tag=trim(str_replace("  "," ",str_replace("&","&amp;",$tag)));
				if($tag!=''){
					$tag_exists = term_exists( $tag, 'product_tag' );

					if($tag_exists){
						$return[] = $tag_exists['term_id'];
					}else{
						$tag_array = wp_insert_term(
							$tag, // the term 
							'product_tag', // the taxonomy
							array(		        
								'slug' => strtolower(str_replace(" ", "-", $tag)),		        
							)
						);

						$return[] = $tag_array['term_id'];
					}
				}
			}
			return $return;
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('add_tags '.$e->getMessage());		
			return $return;
		}
	}

	function check_product_existance($article_id){
		$args = array(
		'post_type'		=> array('product','product_variation'),
		'meta_query' => array(
				array(
					'key' => '_sku',
					'value'     => $article_id,
					'compare'   => '='
				),
			)
		);
		$products = get_posts($args);
				
		if(!empty($products)){
			return $products[0]->ID;
		}else{
			return '';
		}
		
	}

	function update_images($images,$post_id){
		$item = new UpdateImageItem();
		$item->images = $images;
		$item->post_id = $post_id;
		$this->_background_process->push_to_queue($item);
	}
}


class UpdateImageItem{
	var $images;
	var $post_id;
}

class Update_Images_Process extends WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'update_images_process';

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over
	 *
	 * @return mixed
	 */
	protected function task( $item ) {
		$images = $item->images;
		$post_id = $item->post_id;
		global $wc_arcavis_shop;
		// only need these if performing outside of admin environment
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		$list_id = '';
		$first = true;
		foreach ($images as $img) {
			try{
				$id = media_sideload_image($img->Value, $post_id,'','id');
				//Add Image to gallery if upload is successful
				if($id != '' && !is_wp_error($id)){
					//First image will be set as product thumbnail
					if($first){
						set_post_thumbnail($post_id, $id);
						$first=false;
					}
					else{
						$list_id .= $id.',';
					}
				}
			}catch (Exception $e) {
				$wc_arcavis_shop->logError('create_products_init '.$e->getMessage());		
			}
		}
		update_post_meta($post_id,'_product_image_gallery',rtrim($list_id,','));
		return false;
	}

	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		parent::complete();

		// Show notice to user or perform some other arbitrary task...
	}

}


?>