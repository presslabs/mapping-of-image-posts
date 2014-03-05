<?php
/**
 * Plugin Name: Mapping of image posts
 * Plugin URI: http://wordpress.org/extend/plugins/mapping-of-image-posts/
 * Description: Generate a mapping of image - article it belongs, by scanning all attachments.
 * Author: PressLabs
 * Version: 1.2.1
 * Author URI: http://www.presslabs.com/
 */

//--------------------------------------------------------------------
function mapping_of_image_posts_activate() {
  add_option( 'MOIP_MAX_ATTACHMENTS_PER_SCAN', '300', '' );
  add_option( 'moip_image_type', array( '1', '', '', '' ), '' );
  add_option( 'moip_out_file_type', 'nginx', '' );
}
register_activation_hook(__FILE__, 'mapping_of_image_posts_activate');

//--------------------------------------------------------------------
function mapping_of_image_posts_deactivate() {
  delete_option( 'MOIP_MAX_ATTACHMENTS_PER_SCAN' );
  delete_option( 'moip_image_type' );
  delete_option( 'moip_out_file_type' );
}
register_deactivation_hook(__FILE__, 'mapping_of_image_posts_deactivate');

//--------------------------------------------------------------------
// Add settings link on plugin page.
function mapping_of_image_posts_settings_link( $links ) { 
  $plugin = plugin_basename( __FILE__ ); 
  $settings_link = '<a href="tools.php?page=' . $plugin . '&tab=settings">Settings</a>'; 
  array_unshift( $links, $settings_link );

  return $links; 
}
$plugin = plugin_basename( __FILE__ ); 
add_filter("plugin_action_links_$plugin", 'mapping_of_image_posts_settings_link');

//--------------------------------------------------------------------
// Create the subfolder for the resulted files.
function mapping_of_image_posts_mkdir() {
  $path = dirname( $_SERVER['SCRIPT_FILENAME'] ) . "/../wp-content/uploads/mapping-of-image-posts";

  if ( ! is_dir( $path ) )
    if ( ! mkdir( $path, 0755 ) )
      die( "Failed to create folder '$path'" );
}
add_action('admin_init', 'mapping_of_image_posts_mkdir');

//--------------------------------------------------------------------
function mapping_of_image_posts_stylesheet() {
  wp_register_style( 'mapping-of-image-posts-style', plugins_url('/mapping-of-image-posts.css', __FILE__), false, '1.0.0' );
  wp_enqueue_style( 'mapping-of-image-posts-style');
}
add_action('admin_enqueue_scripts', 'mapping_of_image_posts_stylesheet');

//--------------------------------------------------------------------
function mapping_of_image_posts_javascript( $hook ) {
  if ( 'tools_page_mapping-of-image-posts/mapping-of-image-posts' != $hook )
    return;

  $filename = dirname( $_SERVER['SCRIPT_FILENAME'] ) . "/../wp-content/uploads/mapping-of-image-posts/moip" 
    . "-" . time() . "-"
    . mapping_of_image_posts_rand_letter() 
    . mapping_of_image_posts_rand_letter() 
    . mapping_of_image_posts_rand_letter() 
    . ".txt";
  set_transient( 'mapping_of_image_posts_filename', $filename, 1800 );
  wp_enqueue_script( 'mapping-of-image-posts', plugins_url( '/mapping-of-image-posts.js', __FILE__ ), array('jquery'), time() );

  // in javascript, object properties are accessed as ajax_object.ajax_url, ajax_object.we_value
  wp_localize_script( 'ajax-script', 'ajax_object',
  array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'we_value' => $email_nonce ) );
}
add_action('admin_enqueue_scripts', 'mapping_of_image_posts_javascript');

//--------------------------------------------------------------------
function mapping_of_image_posts_json_response( $data = null, $status = 'ok', $status_message = null ) {
  header( "Content-type: application/json" );

  echo json_encode( array(
    'status'  => $status,
    'message' => $status_message,
    'data'    => $data
  ));
}

//--------------------------------------------------------------------
// Return random letter.
function mapping_of_image_posts_rand_letter() {
  $a_z = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
  $int = rand(0, 51);

  return $a_z[ $int ]; // random letter
}

//--------------------------------------------------------------------
// The AJAX callback function
function mapping_of_image_posts_callback() {
  global $wpdb;

  //
  // The diference between apache and nginx mapping files
  //
  $termination_char = "";
  if ( "nginx" == get_option( "moip_out_file_type" ) )
    $termination_char = ";";

  //
  // Get the output filename and the maximum attachments scanned per step.
  //
  $filename = get_transient( 'mapping_of_image_posts_filename' );
  $max_attachments = get_option( 'MOIP_MAX_ATTACHMENTS_PER_SCAN' );

  $pos = 0; // Initialize the start position for scanning process.

  if ( isset( $_POST['pos'] ) )
    $pos = intval( $_POST['pos'] );

  //
  // Reset the file content and the position of scanning process.
  //
  if ( 0 == $pos ) {
    file_put_contents( $filename, '' );
    set_transient( 'lines_affected', 0, 1800 );
  }

  //
  // SELECT the attachments for every scanning step.
  //
  $attachment_ids = $wpdb->get_results( $wpdb->prepare(
    "SELECT ID, post_parent FROM $wpdb->posts WHERE post_type = 'attachment' LIMIT %d, %d", $pos, 
      $max_attachments ) );

  $written = 0; // Count lines written into the output file.
  $scanned = 0; // Count scanned attachments.
  $content = ""; // Retain the output content for each scanning step.
  foreach ( $attachment_ids as $attachment_id ) {
    $moip_image_type = get_option( 'moip_image_type' );

    $post_url = get_permalink( $attachment_id->post_parent );
    $post_parsed = parse_url( $post_url );
    if ( '' < $post_parsed["query"] ) 
      $post_out = $post_parsed["path"] . "?" . $post_parsed["query"];
    else
      $post_out = $post_parsed["path"];

    $image_type_string = array ( 'full', 'thumbnail', 'medium', 'large' );
    $array_image_out = array( null );
    for ( $k = 0; $k < 4; $k++ ) {
      if ( '1' == $moip_image_type[ $k ] ) {
        $attachment_image = wp_get_attachment_image_src( $attachment_id->ID, $image_type_string[ $k ] );
        $image_parsed = parse_url( $attachment_image[0] );
        $image_out = $image_parsed["path"] . $image_parsed["query"];

        if ( ( '' < $image_out ) && ( '' < $post_out ) && ( ! in_array( $image_out, $array_image_out ) ) ) {
          array_push( $array_image_out, $image_out );
          $content .= $image_out . " " . $post_out . $termination_char . "\n";
          $written++;
        }
      }
    }
    $scanned++;
  }
  $lines_affected = get_transient( 'lines_affected' );
  $lines_affected += $written;
  set_transient('lines_affected', $lines_affected, 1800);

  file_put_contents($filename, $content, FILE_APPEND);

  if ( count( $attachment_ids ) < $max_attachments ) {
    $lines_affected = get_transient( 'lines_affected' );

    $message = null;
    if ( 0 < $scanned ) {
      $message = 'Attachments scanned between ' . ($pos + 1) . ' and ' . ($pos + $scanned);
      if ( 1 == $scanned )
        $message = "Attachments scanned " . ($pos + $scanned);
    }

    mapping_of_image_posts_json_response( $lines_affected, 'finish', $message );
    die();
  }

  $next_pos = $pos + $max_attachments;

  $message = null;
  if ( 0 < $scanned ) {
    $message = "Attachments scanned between " . ($pos + 1) . " and " . ($pos + $scanned);
    if ( 1 == $scanned )
      $message = "Attachment scanned " . ($pos + $scanned);
  }

  mapping_of_image_posts_json_response( $next_pos, 'ok', $message );
  die();
}
add_action('wp_ajax_mapping_of_image_posts', 'mapping_of_image_posts_callback');

//----------------------------------------------------------------------------------------------
function mapping_of_image_posts_update_options() {
  $eroare = '';

  $ok = false;
  if ( isset( $_POST['moip_max_scan'] ) ) {
    $moip_max_scan = intval($_POST['moip_max_scan']);
    if ( $moip_max_scan == 0 ) $moip_max_scan = 300;
    update_option('MOIP_MAX_ATTACHMENTS_PER_SCAN', $moip_max_scan);
    $ok = true;
  } else $eroare .= ' [Maximum attachments] ';

  $image_type_value = array( '', '', '', '' );

  $ok = true;
  if ( isset( $_POST['moip_image_type_full'] ) ) {
    $image_type_value[0] = '1';
    $ok = false;
  }
  if ( isset( $_POST['moip_image_type_thumbnail'] ) ) {
    $image_type_value[1] = '1';
    $ok = false;
  }
  if ( isset( $_POST['moip_image_type_medium'] ) ) {
    $image_type_value[2] = '1';
    $ok = false;
  }
  if ( isset( $_POST['moip_image_type_large'] ) ) {
    $image_type_value[3] = '1';
    $ok = false;
  }
  if ( $ok ) $image_type_value[0] = '1';

  update_option('moip_image_type', $image_type_value);

  $ok = false;
  if ( isset( $_POST['moip_out_file_type'] ) ) {
    update_option('moip_out_file_type', $_POST['moip_out_file_type']);
    $ok = true;
  } else $eroare .= ' [Output file type] ';

  if ($ok) { ?>
    <div id="message" class="updated fade">
      <p><strong>Saved options!</strong></p>
    </div>
<?php } else { ?>
    <div id="message" class="error fade">
      <p><strong><span style="color:brown;">Error saving options! (<?php echo $eroare; ?>) </span></strong></p>
    </div>
<?php }
}

//--------------------------------------------------------------------
function mapping_of_image_posts_options() {
  $tab = 'generator'; // default tab
  if ( isset( $_GET['tab'] ) ) $tab = $_GET['tab'];

  if ( isset( $_POST['submit_settings'] ) )	mapping_of_image_posts_update_options();
?>
<div class="wrap">
  <div id="icon-tools" class="icon32">&nbsp;</div>
  <h2 class="nav-tab-wrapper">
    <a class="nav-tab<?php if ( 'generator' == $tab ) echo ' nav-tab-active'; ?>" href="tools.php?page=mapping-of-image-posts/mapping-of-image-posts.php&tab=generator">Mapping Generator</a>
    <a class="nav-tab<?php if ( 'settings' == $tab ) echo ' nav-tab-active'; ?>" href="tools.php?page=mapping-of-image-posts/mapping-of-image-posts.php&tab=settings">Settings</a>
</h2>

<?php if ( 'generator' == $tab ) { ?>
<div class="postbox" style="float:left; display:block; width:auto; height:auto; padding:10px;margin-top:10px;">
  <p>Generate a mapping of image - article it belongs, by scanning all attachments: 
  <input id='moip_start' class="button button-primary" name='moip_start' type='button' value='Scan' autocomplete='off'> </p>

  <div id='messages'>
    <div class="row-title">Ready to scan!</div>
  </div>

  <p><img id="moip_loader" src="<?php echo plugins_url('/ajax-loader.gif', __FILE__); ?>" alt="loading"/></p>

  <p id="moip_download_file">Download resulted file from <a href="<?php echo '/wp-content/uploads/mapping-of-image-posts/' . basename(get_transient('mapping_of_image_posts_filename')); ?>" >here</a></p>
</div><!-- .postbox -->
<?php } ?>

<?php if ( 'settings' == $tab ) {
  $moip_image_type = get_option('moip_image_type');
  $image_type = array('','','','');
  for ( $k = 0; $k < 4; $k++ )
    if ( '1' == $moip_image_type[ $k ] )
      $image_type[ $k ] = ' checked="checked"';

    $moip_out_file_type = get_option('moip_out_file_type');
    $file_type = array('','');
    if ( 'nginx' == $moip_out_file_type )  $file_type[0] = ' checked="checked"';
    if ( 'apache' == $moip_out_file_type ) $file_type[1] = ' checked="checked"';
?>
<form method="post">
  <table class="form-table">
  <tbody>
    <tr valign="top">
    <th scope="row">
      <label for="moip_max_scan">Maximum attachments</label>
    </th>
    <td>
      <input name="moip_max_scan" value="<?php echo get_option('MOIP_MAX_ATTACHMENTS_PER_SCAN', ''); ?>" type="text">
      <p class="description">The maximum number of attachments scanned in one step.</p>
    </td>
    </tr>

    <tr valign="top">
    <th scope="row">
      <label for="moip_image_type">Image type</label>
    </th>
    <td>
      <fieldset>
      <legend class="screen-reader-text"><span>Image type</span></legend>

      <label for="moip_image_type_full">
        <input name="moip_image_type_full" id="moip_image_type_full" value="<?php echo$image_type[0];?>" type="checkbox"<?php echo$image_type[0];?>>
        <span>Full (default)</span>
      </label><br />

      <label for="moip_image_type_thumbnail">
        <input name="moip_image_type_thumbnail" id="moip_image_type_thumbnail" value="<?php echo$image_type[0];?>" type="checkbox"<?php echo$image_type[1];?>>
        <span>Thumbnail</span>
      </label><br />

      <label for="moip_image_type_medium">
        <input name="moip_image_type_medium" id="moip_image_type_medium" value="<?php echo$image_type[0];?>" type="checkbox"<?php echo$image_type[2];?>>
        <span>Medium</span>
      </label><br />

      <label for="moip_image_type_large">
        <input name="moip_image_type_large" id="moip_image_type_large" value="<?php echo$image_type[0];?>" type="checkbox"<?php echo$image_type[3];?>>
        <span>Large</span>
      </label><br />

      <p class="description">The WordPress image type to be scanned.</p>
      </fieldset>
    </td>
    </tr>

    <tr valign="top">
    <th scope="row">
      <label for="moip_out_file_type">Output file type</label>
    </th>
    <td>
      <fieldset>
      <legend class="screen-reader-text"><span>Image type</span></legend>
      <label for="moip_out_file_type_nginx">
        <input name="moip_out_file_type" id="moip_out_file_type_nginx" value="nginx" type="radio"<?php echo$file_type[0];?>>
        <span>nginx</span>
      </label><br />

      <label for="moip_out_file_type_apache">
        <input name="moip_out_file_type" id="moip_out_file_type_apache" value="apache" type="radio"<?php echo$file_type[1];?>>
        <span>Apache</span>
      </label><br />

      <p class="description">The output file type means that is dedicated for Apache or nginx web servers.</p>
      </fieldset>
    </td>
    </tr>
  </tbody>
</table>

<p class="submit">
  <input type="submit" class="button button-primary" name="submit_settings" value="Save Changes">
</p>

</form>
<?php } ?>
</div><!-- .wrap -->
<?php }

//--------------------------------------------------------------------
function mapping_of_image_posts_menu() {
  add_management_page(
    'Mapping - Options', //'custom menu title', 
    'Mapping', //'custom menu', 
    'administrator', //'add_users', 
    __FILE__, //$menu_slug, 
    'mapping_of_image_posts_options'
  );
}
add_action('admin_menu', 'mapping_of_image_posts_menu');
