jQuery(document).ready(function(){
	jQuery('#start_sync').click(function(){
		var confirmaton = confirm('Achtung! Bestehende Produkte und Bestellungen werden gelöscht. Fortfahren?');
		if(confirmaton ===  true){
			initialSync('yes');
		}else{
			return;
		}
		
	});
	function initialSync(delete_or_not){
		
		jQuery('#arcavis_preloader').show();
		jQuery.ajax({
	        url: website_url+"/wp-admin/admin-ajax.php",
	        type : 'post',
	        data: {
	            action :'arcavis_start_initial_sync',
	            delete_or_not : delete_or_not      
	        },
	        success:function(data) {	
	        	if(data == 'continue'){
	        		setTimeout(function(){
	        			initialSync('no');
	        		},10000);
	        	}else{
	        		jQuery('#arcavis_preloader').hide();
	        	}

	        },
	        error: function(errorThrown){
	            console.log(errorThrown);
	        }
	    });
		
	}
});
function sync_on_save_api(delete_or_not){
	jQuery('#arcavis_preloader').show();
	jQuery.ajax({
        url: website_url+"/wp-admin/admin-ajax.php",
        type : 'post',
        data: {
            action :'arcavis_start_initial_sync',
            delete_or_not : delete_or_not      
        },
        success:function(data) {	
        	if(data == 'continue'){
        		setTimeout(function(){
        			sync_on_save_api('no');
        		},5000);
        	}else{
        		jQuery('#arcavis_preloader').hide();
        	}

        },
        error: function(errorThrown){
            console.log(errorThrown);
        }
    });
	
}