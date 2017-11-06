jQuery(document).ready(function(){
	
	jQuery('#billing_email').blur(function(){
     	jQuery('body').trigger('update_checkout');
     	
	});
	jQuery('#arcavis_voucher').blur(function(){
		if(jQuery('#arcavis_voucher').val() != ''){
			jQuery('#arcavis_applied_voucher').val(jQuery('#arcavis_voucher').val()).trigger('change');;
		}
	});
	jQuery('#arcavis_applied_voucher').change(function(){
		jQuery('body').trigger('update_checkout')
		setTimeout(function(){
			jQuery.ajax({
				
		        url: website_url+"/wp-admin/admin-ajax.php",
		        type : 'post',
		        data: {
		            action :'arcavis_get_applied_voucher_code',
		        },
		        success:function(response) {	
		        	 if(response){
		        	 	jQuery(document).find('#applied_voucher_wrapper').remove();

		        	 	data = JSON.parse(response);
		        	 	jQuery('#arcavis_voucher').after("<div id='applied_voucher_wrapper'><h5>Voucher Applied Successfully!</h5>"+data.voucher_code+ ' <a id="arcavis_voucher_remove_link" class="error" href="javascript:void(0)"> X </a></div>');
			        }else{
			        	alert('In valid Voucher');
			        	jQuery('#arcavis_voucher').val('');
			        	jQuery(document).find('#applied_voucher_wrapper').remove();
			        }
		        	

		        },
		        error: function(errorThrown){
		            console.log(errorThrown);
		        }
		    });
		},2000);
				
		
		
	});

	

	/*jQuery( document ).on( 'updated_checkout', function() {
	    if(jQuery.trim(jQuery('#arcavis_voucher').val()) != ''){
	    	jQuery('#arcavis_voucher').blur(function(){
				jQuery.ajax({
					
			        url: website_url+"/wp-admin/admin-ajax.php",
			        type : 'post',
			        data: {
			            action :'arcavis_get_applied_voucher_code',
			        },
			        success:function(response) {	
			        	 if(response){
			        	 	jQuery(document).find('#applied_voucher_wrapper').remove();

			        	 	data = JSON.parse(response);
			        	 	jQuery('#arcavis_voucher').after("<div id='applied_voucher_wrapper'><h5>Voucher Applied Successfully!</h5>"+data.voucher_code+ ' <a id="arcavis_voucher_remove_link" class="error" href="javascript:void(0)"> X </a></div>');
				        }else{
				        	alert('In valid Voucher');
				        	jQuery('#arcavis_voucher').val('');
				        	jQuery(document).find('#applied_voucher_wrapper').remove();
				        }
			        	

			        },
			        error: function(errorThrown){
			            console.log(errorThrown);
			        }
			    });
			});
		}
	

	} );*/

	jQuery(document).on("click",'#arcavis_voucher_remove_link',function(){
		jQuery('#arcavis_voucher').val('');
		jQuery('#arcavis_applied_voucher').val('');
		jQuery(document).find('#applied_voucher_wrapper').remove();		
		jQuery('body').trigger('update_checkout');
	});
});