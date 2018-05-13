<?php
/*This Class Has functions for Syncronize the products from arcavis.*/
set_time_limit(0);
class WooCommerce_Arcavis_Create_Products_Settings{
	
	public function __construct(){

		
	}

	##This function is used for running first time sysc or re-sync.
	public function create_products_init(){			
		global $wc_arcavis_shop;
		global $wpdb;
		try{
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
				$url = $options['arcavis_link'].'/api/articles?mainArticleId=0&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true&pageSize=25&page='.$lastSyncPage;
				$request_args = array(
				  'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] )
				  )
				);

				$product_data = wp_remote_get($url,$request_args);
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
							// Add Images
							$list_id = '';				
							$count = 1;
							if(!empty($product->Images)){
								foreach ($product->Images as $img) {
									$attachment = $this->add_attchement($img->Value,$post_id,$count);
									$list_id .= $attachment.',';
									$count++;
								}
								update_post_meta($post_id,'_product_image_gallery',rtrim($list_id,','));
							}
							// Add Categories
							$categories = $this->add_category($product->MainGroupTitle,$product->TradeGroupTitle,$product->ArticleGroupTitle);
							$categories = array_map( 'intval', $categories );
							wp_set_object_terms( $post_id, $categories, 'product_cat' );
							
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
									$variation_url = $options['arcavis_link'].'/api/articles?mainArticleId='.$product->Id.'&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true';
									$variation_data = wp_remote_get($variation_url,$request_args);
									$variations = json_decode($variation_data['body']);
									if(!empty($variations)){
										foreach ($variations->Result as $key => $variation) {
											$this->insert_product_attributes($post_id, $variation->Attributes);
											$this->insert_product_variations($post_id, $variation, $key);
										}
									}
								}
								   
							}// End of add variations
					}// End of foreach loop

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
					
				}else{
					echo "exit";
					exit;
				}
				exit;
			}
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('create_products_init '.$e->getMessage());
		}
	}

	##This function Delete all Arcavis data from woocommerce including orders.
	public function delete_all_data(){
		global $wpdb;

		$products = $wpdb->get_results("SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation')");
		if(!empty($products)){
			foreach ($products as $product) {				
				wp_delete_attachment( $product->ID, true );
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
						$wpdb->query("DELETE FROM ".$wpdb->prefix."term_relationships WHERE object_id NOT IN (SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type IN ('product','product_variation') )");
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
							update_post_meta( $post_id, '_price', $product->Price );
						}else{
							$SalePrice = $product->SalePrice;
							update_post_meta( $post_id, '_price', $SalePrice);
						}
						
						update_post_meta($post_id, '_sku', $product->ArticleNumber );
						update_post_meta( $post_id,'article_id',$product->Id);
						update_post_meta( $post_id, '_regular_price', $product->Price );
						update_post_meta( $post_id, '_sale_price', $SalePrice );			
						update_post_meta( $post_id,'_visibility','visible');	
												
						$list_id = '';				
						$count = 1;
						if(!empty($product->Images)){
							foreach ($product->Images as $img) {
								$attachment = $this->add_attchement($img->Value,$post_id,$count);
								$list_id .= $attachment.',';
								$count++;
							}
							update_post_meta($post_id,'_product_image_gallery',rtrim($list_id,','));
						}
						// Set categories
						$categories = $this->add_category($product->MainGroupTitle,$product->TradeGroupTitle,$product->ArticleGroupTitle);
						$categories = array_map( 'intval', $categories );
						wp_set_object_terms( $post_id, $categories, 'product_cat' );
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
								$variation_url = $options['arcavis_link'].'/api/articles?mainArticleId='.$product->Id.'&inclStock=true&inclTags=true&ignSupp=true&ignIdents=true';
								$variation_data = wp_remote_get($variation_url,$request_args);
								$variations = json_decode($variation_data['body']);
								if(!empty($variations)){
									foreach ($variations->Result as $key => $variation) {
										$this->insert_product_attributes($post_id, $variation->Attributes);
										$this->insert_product_variations($post_id, $variation, $key);
									}
								}
							}
							   
						}
						
					}// End of foreach loop
					
					$wpdb->update($wpdb->prefix."lastSyncTicks", array('lastSync'=>$products->DataAgeTicks), array('apiName'=>'articles'));
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
				update_post_meta($products[0]->ID, '_manage_stock', 'yes' );
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
					update_post_meta($products[0]->ID, '_manage_stock', 'yes' );
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


	public function insert_product_variations ($post_id, $variation,$index){  
		$check_product = $this->check_product_existance($variation->ArticleNumber);

		if($variation->Status == '0'){
			$Status = 'publish';
		}elseif($variation->Status == '1'){
			$Status = 'trash';
		}elseif($variation->Status == '2'){
			$Status = 'trash';
		}
		if($check_product != ''){

			$variation_post_id = $check_product;
			$variation_post = array( // Setup the post data for the variation
				'ID'           => $variation_post_id,
				'post_title'  => $variation->Title,
				'post_name'   => str_replace(" ", "-", $variation->Title),
				'post_status' => $Status,
				'post_parent' => $post_id,
				'post_type'   => 'product_variation',
				'guid'        => home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index
			);
			
			wp_update_post( $variation_post );
		}else{
			$variation_post = array( // Setup the post data for the variation

				'post_title'  => $variation->Title,
				'post_name'   => str_replace(" ", "-", $variation->Title),
				'post_status' => $Status,
				'post_parent' => $post_id,
				'post_type'   => 'product_variation',
				'guid'        => home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index
			);
			$variation_post_id = wp_insert_post($variation_post); // Insert the variation
		}
		

		foreach ($variation->Attributes as $attr){ // Loop through the variations attributes
			// Ignore Season
			if($attr->Name!="Season"){
			wp_set_object_terms( $post_id, $attr->Value, 'pa_' . str_replace(" ","-",strtolower($attr->Name)), true );
			$attribute_term = get_term_by('name', $attr->Value, 'pa_'.str_replace(" ","-",strtolower($attr->Name))); // We need to insert the slug not the name into the variation post meta

			update_post_meta($variation_post_id, 'attribute_pa_'.str_replace(" ","-",strtolower($attr->Name)), $attribute_term->slug);
			// Again without variables: update_post_meta(25, 'attribute_pa_size', 'small')
			}
		}
		if($variation->SalePrice == 0){
			$SalePrice = '';
		}else{
			$SalePrice = $variation->SalePrice;
		}
		$stockstatus='instock';
		if($variation->Stock<=0){
			$stockstatus='outofstock';
		}
		update_post_meta( $variation_post_id, '_sale_price', $SalePrice );
		update_post_meta($variation_post_id, '_price', $variation->Price);
		update_post_meta($variation_post_id, '_regular_price', $variation->Price);
		update_post_meta($variation_post_id, '_sku', $variation->ArticleNumber );
		update_post_meta( $variation_post_id,'article_id',$variation->Id);
		update_post_meta($variation_post_id, '_manage_stock', 'yes' );
		update_post_meta( $variation_post_id,'_stock_status',$stockstatus);
		update_post_meta( $variation_post_id,'_stock',$variation->Stock);
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
	
	
	public function add_attchement($image,$post_id,$image_number){
	    global $wc_arcavis_shop;
		$return = '';
		try{
			// only need these if performing outside of admin environment
			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			// magic sideload image returns an HTML image, not an ID
			$media = media_sideload_image($image, $post_id);
			
			// therefore we must find it so we can set it as featured ID
			if(!empty($media) && !is_wp_error($media)){
				$args = array(
					'post_type' => 'attachment',
					'posts_per_page' => -1,
					'post_status' => 'any',
					'post_parent' => $post_id
				);

				// reference new image to set as featured
				$attachments = get_posts($args);
				
				if(isset($attachments) && is_array($attachments)){
					foreach($attachments as $attachment){
						// grab source of full size images (so no 300x150 nonsense in path)
						$image = wp_get_attachment_image_src($attachment->ID, 'full');

						// determine if in the $media image we created, the string of the URL exists
						
						if(strpos($media, $image[0]) !== false){
							$return = $attachment->ID;
							if($image_number == 1){
							// if so, we found our image. set it as thumbnail
							set_post_thumbnail($post_id, $attachment->ID);
							// only want one image
								break;
							}
						}
						
					}
				}

				return $return;
			}
		}catch (Exception $e) 
		{
			$wc_arcavis_shop->logError('create_products_init '.$e->getMessage());		
			return $return;
		}
	}

	function check_product_existance($article_id){
		$args = array(
		'post_type'		=>	'product',
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
}

?>