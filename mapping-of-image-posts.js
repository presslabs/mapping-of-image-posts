jQuery(document).ready(function($) {
  var messages = $('#messages');
  var pos = 0;
  var alter = 1;

  function update() {
    $.ajax( ajaxurl, {
      type    : 'POST',
      async   : true,
      data    : { action: 'mapping_of_image_posts', pos: pos },
      success : function( response ) {
        alter++;
        alternate = '';
        if ( ( alter % 2 ) == 0 ) alternate = ' class="alternate"';
        var e = $('<div'+alternate+'></div>');

        if ( 'finish' == response.status ) {
          e = $('<div'+alternate+'></div>');
          $('#moip_loader').hide();
          $('#moip_download_file').show();
        }

        if (response.status == 'error') {
          e.addClass('error').text(response.message);
        } else {
          pos = response.data;
          if (response.status != 'finish') update();

          if ( response.message ) {
            e.text(response.message);
            messages.append(e);
          }
        }

        if (response.status == 'finish') {
          e = $('<div class="row-title"></div>').text('Number of unique images written: ' + response.data);
          messages.append(e);
        }
      },
      error: function() {
      }
    });		
  }

  $('#moip_start').click( function() {
    $(this).attr('disabled',true);
    $('#moip_loader').show();
    update();
  });
});
