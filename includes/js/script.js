jQuery(document).ready(function(){
	jQuery('#start_sync').off('click').on('click',function(){
        startSync();
    });

    function startSync() {
        var confirmaton = confirm('Achtung! Bestehende Produkte und Bestellungen werden gelöscht. Fortfahren?');
        if (confirmaton === true) {
            syncLoop('yes');
        } else {
            return;
        }
    }

    function syncLoop(delete_or_not){
		jQuery('#arcavis_preloader').show();
		jQuery.ajax({
	        url: website_url+"/wp-admin/admin-ajax.php",
	        type : 'post',
	        data: {
	            action :'arcavis_start_initial_sync',
	            delete_or_not : delete_or_not      
	        },
            success: function (data) {	
                jQuery('#arcavis_preloader').hide();
	        	if(data == 'continue'){
	        		setTimeout(function(){
                        syncLoop('no');
	        		},10000);
	        	}

	        },
	        error: function(errorThrown){
	            console.log(errorThrown);
	        }
	    });
		
	}
});