<?php

class WPSDK {
  
  function __construct() {
    $this->query = new WPSDK_Query();
    $this->introspector = new WPSDK_Introspector();
    $this->response = new WPSDK_Response();
    add_action('template_redirect', array(&$this, 'template_redirect'));
    add_action('admin_menu', array(&$this, 'admin_menu'));
    add_action('update_option_wpsdk_base', array(&$this, 'flush_rewrite_rules'));
    add_action('pre_update_option_wpsdk_controllers', array(&$this, 'update_controllers'));
  }
  
  function template_redirect() {
    // Check to see if there's an appropriate API controller + method    
    $controller = strtolower($this->query->get_controller());
    $available_controllers = $this->get_controllers();
    $enabled_controllers = explode(',', get_option('wpsdk_controllers', 'core'));
    $active_controllers = array_intersect($available_controllers, $enabled_controllers);
    
    if ($controller) {
      
      if (empty($this->query->dev)) {
        error_reporting(0);
      }
      
      if (!in_array($controller, $active_controllers)) {
        $this->error("Unknown controller '$controller'.");
      }
      
      $controller_path = $this->controller_path($controller);
      if (file_exists($controller_path)) {
        require_once $controller_path;
      }
      $controller_class = $this->controller_class($controller);
      
      if (!class_exists($controller_class)) {
        $this->error("Unknown controller '$controller_class'.");
      }
      
      $this->controller = new $controller_class();
      $method = $this->query->get_method($controller);
      
      if ($method) {
        
        $this->response->setup();

        // Run action hooks for method
        do_action("json_api", $controller, $method);
        do_action("json_api-{$controller}-$method");
        
        // Error out if nothing is found
        if ($method == '404') {
          $this->error('Not found');
        }
        
        // Run the method
        $result = $this->controller->$method();
        
        // Handle the result
        $this->response->respond($result);
        
        // Done!
        exit;
      }
    }
  }
  
  function admin_menu() {
    add_options_page('JSON API Settings', 'MobPress', 'manage_options', 'wpsdk', array(&$this, 'admin_options'));
  }
  
  function admin_options() {
    if (!current_user_can('manage_options'))  {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }

    wp_enqueue_script( 'json2' );
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'jquery-form' );
    
    if($_POST){
        if(!empty($_REQUEST['init'])){
            $this->init_wpsdk();
        }else{
            $this->set_banners();
        }
    }
    $banners = $this->get_banners();

    $available_controllers = $this->get_controllers();
    $active_controllers = explode(',', get_option('wpsdk_controllers', 'core'));
    
    if (count($active_controllers) == 1 && empty($active_controllers[0])) {
      $active_controllers = array();
    }
    
    if (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], "update-options")) {
      if ((!empty($_REQUEST['action']) || !empty($_REQUEST['action2'])) &&
          (!empty($_REQUEST['controller']) || !empty($_REQUEST['controllers']))) {
        if (!empty($_REQUEST['action'])) {
          $action = $_REQUEST['action'];
        } else {
          $action = $_REQUEST['action2'];
        }
        
        if (!empty($_REQUEST['controllers'])) {
          $controllers = $_REQUEST['controllers'];
        } else {
          $controllers = array($_REQUEST['controller']);
        }
        
        foreach ($controllers as $controller) {
          if (in_array($controller, $available_controllers)) {
            if ($action == 'activate' && !in_array($controller, $active_controllers)) {
              $active_controllers[] = $controller;
            } else if ($action == 'deactivate') {
              $index = array_search($controller, $active_controllers);
              if ($index !== false) {
                unset($active_controllers[$index]);
              }
            }
          }
        }
        $this->save_option('wpsdk_controllers', implode(',', $active_controllers));
      }
    }
    
    ?>
<div class="wrap">
  <div id="icon-options-general" class="icon32"><br /></div>
  <h2>MobPress Settings</h2>
  <h3>MobPress初始化</h3>
    <form action="options-general.php?page=wpsdk&init=1" method="post" enctype="multipart/form-data" >
    <table class="form-table">
      <tr>
        <th><label for="category_base">APPKEY</label></th>
        <td> <input name="wpsdk_appkey" id="wpsdk_appkey" type="text" value="<?php echo get_option('wpsdk_appkey'); ?>" class="regular-text code"></td>
      </tr>
      <tr>
        <th><label for="category_base">APPSECRET</label></th>
        <td> <input name="wpsdk_appsecret" id="wpsdk_appsecret" type="password" value="<?php echo get_option('wpsdk_appsecret'); ?>" class="regular-text code"></td>
      </tr> 
    </table>
    <p class="submit">
      <input type="submit" class="button-primary" value="初始化MobPress">
    </p>
    </form>
    <hr/>
  <form action="options-general.php?page=wpsdk" method="post" enctype="multipart/form-data" >
    <?php wp_nonce_field('update-options'); ?>
    <h3>Controllers</h3>
    <table id="all-plugins-table" class="widefat">
      <tbody class="plugins">
        <?php
        
        foreach ($available_controllers as $controller) {
          
          $error = false;
          $active = in_array($controller, $active_controllers);
          $info = $this->controller_info($controller);
          
          if (is_string($info)) {
            $active = false;
            $error = true;
            $info = array(
              'name' => $controller,
              'description' => "<p><strong>Error</strong>: $info</p>",
              'methods' => array(),
              'url' => null
            );
          }
          
          ?>
          <tr class="<?php echo ($active ? 'active' : 'inactive'); ?>">
            <th class="check-column" scope="row">
              <input type="checkbox" name="controllers[]" value="<?php echo $controller; ?>" />
            </th>
            <td class="plugin-title">
              <strong><?php echo $info['name']; ?></strong>
              <div class="row-actions-visible">
                <?php
                
                if ($active) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=wpsdk&amp;action=deactivate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Deactivate this controller') . '" class="edit">' . __('Deactivate') . '</a>';
                } else if (!$error) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=wpsdk&amp;action=activate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Activate this controller') . '" class="edit">' . __('Activate') . '</a>';
                }
                  
                if (!empty($info['url'])) {
                  echo ' | ';
                  echo '<a href="' . $info['url'] . '" target="_blank">Docs</a></div>';
                }
                
                ?>
            </td>
            <td class="desc">
              <p><?php echo $info['description']; ?></p>
              <p>
                <?php
                
                foreach($info['methods'] as $method) {
                  $url = $this->get_method_url($controller, $method);
                  if ($active) {
                    echo "<code><a href=\"$url\">$method</a></code> ";
                  } else {
                    echo "<code>$method</code> ";
                  }
                }
                
                ?>
              </p>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <br/><hr/>
    <h3>Banners设置</h3>
    <table class="form-table"> 
      <?php for ($i=1;$i<6;$i++){?>
          <tr>
            <th rowspan="2"><label for="category_base">Banner<?php echo $i?></label></th>
            <td> 
                <input name="wpsdk_banner<?php echo $i?>_pic" id="wpsdk_banner<?php echo $i?>_pic" type="text" value="<?php echo isset($banners['banner_'.$i])?$banners['banner_'.$i]['image_url']:''; ?>" placeholder="banner<?php echo $i?>地址" class="regular-text code">
                <input type="button" onclick="selectPic('wpsdk_banner<?php echo $i?>_pic');" value="上传图片"/>
            </td>
          </tr>
          <tr>
              <td> <input name="wpsdk_banner<?php echo $i?>_link" id="wpsdk_banner<?php echo $i?>_link" type="text" value="<?php echo isset($banners['banner_'.$i])?$banners['banner_'.$i]['jump_link']:''; ?>" placeholder="banner<?php echo $i?>链接"  class="regular-text code"></td>
          </tr>
      <?php }?>      
    </table>
    <p class="submit">
      <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
  </form>
    <form id="jvForm" method="post" hidden="hidden" enctype="multipart/form-data">
        <input name="file" type="file" id='u'onchange="uploadPic();"/>
    </form>
</div>
<script type="text/javascript">
//    jQuery(document).ready(function($) {
        var input_id = '';
        function selectPic(id){
            input_id = id;
            jQuery('#u').click();
        }
        function uploadPic() {  
            // 上传设置  
            var options = {  
                    // 规定把请求发送到那个URL  
                    url: "http://mscq.service.mob.com/v1/common/upload",  
                    // 请求方式  
                    type: "post",  
                    // 服务器响应的数据类型  
                    dataType: "json",  
                    // 请求成功时执行的回调函数  
                    success: function(data, status, xhr) {  
                        // 图片显示地址
                        console.log(data);
                        if(data.status==200){
                            jQuery("input[name='"+input_id+"']").val(data.res);
                        }else{
                            var err = 'data.status:'+data.status+'\r\n'
                                + 'data.error:'+data.error+'\r\n';
                            alert(err);
                        }
                    },
                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                        console.log(XMLHttpRequest);
                        console.log(textStatus);
                        console.log(errorThrown);
                        var err = 'XMLHttpRequest.readyState:'+XMLHttpRequest.readyState+'\r\n'
                                + 'XMLHttpRequest.statusText:'+XMLHttpRequest.statusText+'\r\n';
                        alert(err);
                    }
            };  
            jQuery("#jvForm").ajaxSubmit(options);  
        }
//    });
    
</script> 
<?php
    }
    function print_controller_actions($name = 'action') {
    ?>
    <div class="tablenav">
      <div class="alignleft actions">
        <select name="<?php echo $name; ?>">
          <option selected="selected" value="-1">Bulk Actions</option>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
        </select>
        <input type="submit" class="button-secondary action" id="doaction" name="doaction" value="Apply">
      </div>
      <div class="clear"></div>
    </div>
    <div class="clear"></div>
    <?php
  }
  function init_wpsdk(){
        if (isset($_REQUEST['wpsdk_appkey'])) {
          $this->save_option('wpsdk_appkey', $_REQUEST['wpsdk_appkey']);
        }
        if (isset($_REQUEST['wpsdk_appsecret'])) {
          $this->save_option('wpsdk_appsecret', $_REQUEST['wpsdk_appsecret']);
        }
        
        $body      = "select * from wp_host_config order by create_at desc limit 0,1;";
        $res_body  = $this->send_to_mob($body);
        if(isset($res_body['status'])&&$res_body['status']==200&&isset($res_body['res']['count'])){
            $host_url  = get_bloginfo('url');
            if($res_body['res']['count']){
                $body = "update wp_host_config set host_url = '{$host_url}' where object_id='{$res_body['res']['list'][0]['object_id']}';";
            }else{
                $body = "insert into wp_host_config(host_url) values('{$host_url}');";
            }
        }
        $res_body  = $this->send_to_mob($body);        
        $this->mob_msg($res_body,'初始化成功');
  }
    function mob_msg($res_body,$message){
      $msg = '';
        if($res_body['status']!==200){
            $msg = "<div id=\"json-api-warning\" class=\"updated fade\"><p>异常！错误信息:code={$res_body['status']} msg={$res_body['error']}</p></div>";
        }else if($res_body['status']==200){
            $msg = "<div id=\"json-api-warning\" class=\"updated fade\"><p>".$message."</p></div>";
        }
        echo $msg;
    }
    function get_banners(){
        $body      = "select * from wp_banners_one order by create_at desc limit 0,1;";
        $res_body  = $this->send_to_mob($body);
        $banners   = array();
        if(isset($res_body['status'])&&$res_body['status']==200){
            $banners = $res_body['res']['list'][0];
        }
        return $banners;
    }
    function set_banners(){
        for($i=1;$i<6;$i++){
            $this->save_option("wpsdk_banner{$i}_pic", trim($_REQUEST["wpsdk_banner{$i}_pic"]));
            $this->save_option("wpsdk_banner{$i}_link", trim($_REQUEST["wpsdk_banner{$i}_link"]));
        }
        $body      = "select * from wp_banners_one order by create_at desc limit 0,1;";
        $res_body  = $this->send_to_mob($body);
        if(isset($res_body['status'])&&$res_body['status']==200&&isset($res_body['res']['count'])){
            if($res_body['res']['count']){
                $body      = "update wp_banners_one set "
                        . "banner_1='{\"image_url\":\"".get_option('wpsdk_banner1_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner1_link')."\"}',"
                        . "banner_2='{\"image_url\":\"".get_option('wpsdk_banner2_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner2_link')."\"}',"
                        . "banner_3='{\"image_url\":\"".get_option('wpsdk_banner3_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner3_link')."\"}',"
                        . "banner_4='{\"image_url\":\"".get_option('wpsdk_banner4_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner4_link')."\"}',"
                        . "banner_5='{\"image_url\":\"".get_option('wpsdk_banner5_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner5_link')."\"}' "
                        . "where object_id='".$res_body['res']['list'][0]['object_id']."';";
            }else{
                $body      = "insert into wp_banners_one (banner_1,banner_2,banner_3,banner_4,banner_5) values(
                                '{\"image_url\":\"".get_option('wpsdk_banner1_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner1_link')."\"}',
                                '{\"image_url\":\"".get_option('wpsdk_banner2_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner2_link')."\"}',
                                '{\"image_url\":\"".get_option('wpsdk_banner3_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner3_link')."\"}',
                                '{\"image_url\":\"".get_option('wpsdk_banner4_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner4_link')."\"}',
                                '{\"image_url\":\"".get_option('wpsdk_banner5_pic')."\",\"jump_link\":\"".get_option('wpsdk_banner5_link')."\"}');";
            }
            $res_body  = $this->send_to_mob($body);
            $this->mob_msg($res_body,"Banners设置成功");
        }
  }
  function send_to_mob($body){
      $url = 'http://test.zhouzhipeng.com:8083/v0/msc/w';
      $url = 'http://mscq.service.mob.com/v0/msc/w';
      if (preg_match("/select/i", $body)) {
          $url = 'http://mscq.service.mob.com/v0/msc/r';
      }
      $appsecret               = get_option('wpsdk_appsecret');
      $headers['content-type'] = 'text/plain'; 
      $headers['charset']      = 'UTF-8'; 
      $headers['key']          = get_option('wpsdk_appkey');
      $headers['sign']         = md5($body.$appsecret);      

      $res = Requests::post($url,$headers, $body);
      if($res&&$res->body){
          $res_body = json_decode($res->body,true);
          return $res_body;
      }
      return array('status'=>null,'error'=>'通信异常！');
  }
  function get_method_url($controller, $method, $options = '') {
    $url = get_bloginfo('url');
    $base = get_option('wpsdk_base', 'api');
    $permalink_structure = get_option('permalink_structure', '');
    if (!empty($options) && is_array($options)) {
      $args = array();
      foreach ($options as $key => $value) {
        $args[] = urlencode($key) . '=' . urlencode($value);
      }
      $args = implode('&', $args);
    } else {
      $args = $options;
    }
    if ($controller != 'core') {
      $method = "$controller/$method";
    }
    if (!empty($base) && !empty($permalink_structure)) {
      if (!empty($args)) {
        $args = "?$args";
      }
      return "$url/$base/$method/$args";
    } else {
      return "$url?wpsdk=$method&$args";
    }
  }
  
  function save_option($id, $value) {
    $option_exists = (get_option($id, null) !== null);
    if ($option_exists) {
      update_option($id, $value);
    } else {
      add_option($id, $value);
    }
  }
  
  function get_controllers() {
    $controllers = array();
    $dir = wpsdkDir();
    $this->check_directory_for_controllers("$dir/controllers", $controllers);
    $this->check_directory_for_controllers(get_stylesheet_directory(), $controllers);
    $controllers = apply_filters('wpsdk_controllers', $controllers);
    return array_map('strtolower', $controllers);
  }
  
  function check_directory_for_controllers($dir, &$controllers) {
    $dh = opendir($dir);
    while ($file = readdir($dh)) {
      if (preg_match('/(.+)\.php$/i', $file, $matches)) {
        $src = file_get_contents("$dir/$file");
        if (preg_match("/class\s+WPSDK_{$matches[1]}_Controller/i", $src)) {
          $controllers[] = $matches[1];
        }
      }
    }
  }
  
  function controller_is_active($controller) {
    if (defined('WPSDK_CONTROLLERS')) {
      $default = WPSDK_CONTROLLERS;
    } else {
      $default = 'core';
    }
    $active_controllers = explode(',', get_option('wpsdk_controllers', $default));
    return (in_array($controller, $active_controllers));
  }
  
  function update_controllers($controllers) {
    if (is_array($controllers)) {
      return implode(',', $controllers);
    } else {
      return $controllers;
    }
  }
  
  function controller_info($controller) {
    $path = $this->controller_path($controller);
    $class = $this->controller_class($controller);
    $response = array(
      'name' => $controller,
      'description' => '(No description available)',
      'methods' => array()
    );
    if (file_exists($path)) {
      $source = file_get_contents($path);
      if (preg_match('/^\s*Controller name:(.+)$/im', $source, $matches)) {
        $response['name'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller description:(.+)$/im', $source, $matches)) {
        $response['description'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller URI:(.+)$/im', $source, $matches)) {
        $response['docs'] = trim($matches[1]);
      }
      if (!class_exists($class)) {
        require_once($path);
      }
      $response['methods'] = get_class_methods($class);
      return $response;
    } else if (is_admin()) {
      return "Cannot find controller class '$class' (filtered path: $path).";
    } else {
      $this->error("Unknown controller '$controller'.");
    }
    return $response;
  }
  
  function controller_class($controller) {
    return "wpsdk_{$controller}_controller";
  }
  
  function controller_path($controller) {
    $wpsdk_api_dir = wpsdkDir();
    $wpsdk_api_path = "$wpsdk_api_dir/controllers/$controller.php";
    $theme_dir = get_stylesheet_directory();
    $theme_path = "$theme_dir/$controller.php";
    if (file_exists($theme_path)) {
      $path = $theme_path;
    } else if (file_exists($wpsdk_api_path)) {
      $path = $wpsdk_api_path;
    } else {
      $path = null;
    }
    $controller_class = $this->controller_class($controller);
    return apply_filters("{$controller_class}_path", $path);
  }
  
  function get_nonce_id($controller, $method) {
    $controller = strtolower($controller);
    $method = strtolower($method);
    return "json_api-$controller-$method";
  }
  
  function flush_rewrite_rules() {
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }
  
  function error($message = 'Unknown error', $http_status = 200,$error_code = 0) {
    $this->response->respond(array(
      'error' => $message,
      'error_code'=>$error_code
    ), 'error', $http_status);
  }
  
  function include_value($key) {
    return $this->response->is_value_included($key);
  }
  
}

?>
