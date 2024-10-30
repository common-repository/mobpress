<?php
/*
Controller name: Respond
Controller description: Comment/trackback submission methods
*/

class WPSDK_Respond_Controller {
  
  function submit_comment() {
    global $wpsdk_api;
    nocache_headers();
    if (empty($_REQUEST['post_id'])) {
      $wpsdk_api->error("No post specified. Include 'post_id' var in your request.");
    } else if (empty($_REQUEST['name']) ||
               empty($_REQUEST['email']) ||
               empty($_REQUEST['content'])) {
      $wpsdk_api->error("Please include all required arguments (name, email, content).");
    } else if (!is_email($_REQUEST['email'])) {
      $wpsdk_api->error("Please enter a valid email address.");
    }
    $pending = new WPSDK_Comment();
    return $pending->handle_submission();
  }
  
}

?>
