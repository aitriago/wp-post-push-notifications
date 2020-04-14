/* javascript */
jQuery(document).ready( function(){    
    console.log(">>>   JQUERY URL:");
    console.log(wp_push_notifications_ajax.ajax_url);
    jQuery('a.wpn_bttn').on('click',  function(e) { 
        e.preventDefault();
        var element=jQuery(this);
        var wpn_post_id = jQuery(this).data( 'id' );    
        jQuery.ajax({
            url : wp_push_notifications_ajax.ajax_url,
            type : 'post',            
            dataType: 'json',
            data : {
                action : 'notify_now',
                post_id : wpn_post_id
            },
            success : function( response ) {
                console.log(response);
                var p=element.parent();
                var div=jQuery("<div>",{class:'wpn_bttn sent'})
                .append("Programmé");
                element.show();
                element.replaceWith(div);
            },
        });
        jQuery(this).hide();            
    });     
}); 