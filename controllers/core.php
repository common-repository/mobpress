<?php
/*
Controller name: Core
Controller description: Basic introspection methods
*/

class WPSDK_Core_Controller {
  
  public function info() {
    global $wpsdk_api;
    $php = '';
    if (!empty($wpsdk_api->query->controller)) {
      return $wpsdk_api->controller_info($wpsdk_api->query->controller);
    } else {
      $dir = wpsdkDir();
      if (file_exists("$dir/json-api.php")) {
        $php = file_get_contents("$dir/json-api.php");
      } else {
        // Check one directory up, in case json-api.php was moved
        $dir = dirname($dir);
        if (file_exists("$dir/json-api.php")) {
          $php = file_get_contents("$dir/json-api.php");
        }
      }
      if (preg_match('/^\s*Version:\s*(.+)$/m', $php, $matches)) {
        $version = $matches[1];
      } else {
        $version = '(Unknown)';
      }
      $active_controllers = explode(',', get_option('wpsdk_controllers', 'core'));
      $controllers = array_intersect($wpsdk_api->get_controllers(), $active_controllers);
      return array(
        'wpsdk_version' => $version,
        'controllers' => array_values($controllers)
      );
    }
  }
  
  public function get_recent_posts() {
    global $wpsdk_api;
    $posts = $wpsdk_api->introspector->get_posts();
    return $this->posts_result($posts);
  }
  
  public function get_posts() {
    global $wpsdk_api;
    $url = parse_url($_SERVER['REQUEST_URI']);
    $defaults = array(
      'ignore_sticky_posts' => true
    );
    $query = wp_parse_args($url['query']);
    unset($query['json']);
    unset($query['post_status']);
    $query = array_merge($defaults, $query);
    $posts = $wpsdk_api->introspector->get_posts($query);
    $result = $this->posts_result($posts);
    $result['query'] = $query;
    return $result;
  }
  
  public function get_post() {
    global $wpsdk_api, $post;
    $post = $wpsdk_api->introspector->get_current_post();
    if ($post) {
      $previous = get_adjacent_post(false, '', true);
      $next = get_adjacent_post(false, '', false);
      $response = array(
        'post' => new WPSDK_Post($post)
      );
      if ($previous) {
        $response['previous_url'] = get_permalink($previous->ID);
      }
      if ($next) {
        $response['next_url'] = get_permalink($next->ID);
      }
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: Origin, Content-Type, Cookie, Accept");
        header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, OPTIONS");
        header("Access-Control-Allow-Credentials: false");
      return $response;
    } else {
      $wpsdk_api->error("Not found.",200,404);
    }
  }

  public function get_page() {
    global $wpsdk_api;
    extract($wpsdk_api->query->get(array('id', 'slug', 'page_id', 'page_slug', 'children')));
    if ($id || $page_id) {
      if (!$id) {
        $id = $page_id;
      }
      $posts = $wpsdk_api->introspector->get_posts(array(
        'page_id' => $id
      ));
    } else if ($slug || $page_slug) {
      if (!$slug) {
        $slug = $page_slug;
      }
      $posts = $wpsdk_api->introspector->get_posts(array(
        'pagename' => $slug
      ));
    } else {
      $wpsdk_api->error("Include 'id' or 'slug' var in your request.");
    }
    
    // Workaround for https://core.trac.wordpress.org/ticket/12647
    if (empty($posts)) {
      $url = $_SERVER['REQUEST_URI'];
      $parsed_url = parse_url($url);
      $path = $parsed_url['path'];
      if (preg_match('#^http://[^/]+(/.+)$#', get_bloginfo('url'), $matches)) {
        $blog_root = $matches[1];
        $path = preg_replace("#^$blog_root#", '', $path);
      }
      if (substr($path, 0, 1) == '/') {
        $path = substr($path, 1);
      }
      $posts = $wpsdk_api->introspector->get_posts(array('pagename' => $path));
    }
    
    if (count($posts) == 1) {
      if (!empty($children)) {
        $wpsdk_api->introspector->attach_child_posts($posts[0]);
      }
      return array(
        'page' => $posts[0]
      );
    } else {
      $wpsdk_api->error("Not found.");
    }
  }
  
  public function get_date_posts() {
    global $wpsdk_api;
    if ($wpsdk_api->query->date) {
      $date = preg_replace('/\D/', '', $wpsdk_api->query->date);
      if (!preg_match('/^\d{4}(\d{2})?(\d{2})?$/', $date)) {
        $wpsdk_api->error("Specify a date var in one of 'YYYY' or 'YYYY-MM' or 'YYYY-MM-DD' formats.");
      }
      $request = array('year' => substr($date, 0, 4));
      if (strlen($date) > 4) {
        $request['monthnum'] = (int) substr($date, 4, 2);
      }
      if (strlen($date) > 6) {
        $request['day'] = (int) substr($date, 6, 2);
      }
      $posts = $wpsdk_api->introspector->get_posts($request);
    } else {
      $wpsdk_api->error("Include 'date' var in your request.");
    }
    return $this->posts_result($posts);
  }
  
  public function get_category_posts() {
    global $wpsdk_api;
    $category = $wpsdk_api->introspector->get_current_category();
    if (!$category) {
      $wpsdk_api->error("Not found.");
    }
    $posts = $wpsdk_api->introspector->get_posts(array(
      'cat' => $category->id
    ));
    return $this->posts_object_result($posts, $category);
  }
  
  public function get_tag_posts() {
    global $wpsdk_api;
    $tag = $wpsdk_api->introspector->get_current_tag();
    if (!$tag) {
      $wpsdk_api->error("Not found.");
    }
    $posts = $wpsdk_api->introspector->get_posts(array(
      'tag' => $tag->slug
    ));
    return $this->posts_object_result($posts, $tag);
  }
  
  public function get_author_posts() {
    global $wpsdk_api;
    $author = $wpsdk_api->introspector->get_current_author();
    if (!$author) {
      $wpsdk_api->error("Not found.");
    }
    $posts = $wpsdk_api->introspector->get_posts(array(
      'author' => $author->id
    ));
    return $this->posts_object_result($posts, $author);
  }
  
  public function get_search_results() {
    global $wpsdk_api;
    if ($wpsdk_api->query->search) {
      $posts = $wpsdk_api->introspector->get_posts(array(
        's' => $wpsdk_api->query->search
      ));
    } else {
      $wpsdk_api->error("Include 'search' var in your request.");
    }
    return $this->posts_result($posts);
  }
  
  public function get_date_index() {
    global $wpsdk_api;
    $permalinks = $wpsdk_api->introspector->get_date_archive_permalinks();
    $tree = $wpsdk_api->introspector->get_date_archive_tree($permalinks);
    return array(
      'permalinks' => $permalinks,
      'tree' => $tree
    );
  }
  
  public function get_category_index() {
    global $wpsdk_api;
    $args = null;
    if (!empty($wpsdk_api->query->parent)) {
      $args = array(
        'parent' => $wpsdk_api->query->parent
      );
    }
    $categories = $wpsdk_api->introspector->get_categories($args);
    return array(
      'count' => count($categories),
      'categories' => $categories
    );
  }
  
  public function get_tag_index() {
    global $wpsdk_api;
    $tags = $wpsdk_api->introspector->get_tags();
    return array(
      'count' => count($tags),
      'tags' => $tags
    );
  }
  
  public function get_author_index() {
    global $wpsdk_api;
    $authors = $wpsdk_api->introspector->get_authors();
    return array(
      'count' => count($authors),
      'authors' => array_values($authors)
    );
  }
  
  public function get_page_index() {
    global $wpsdk_api;
    $pages = array();
    $post_type = $wpsdk_api->query->post_type ? $wpsdk_api->query->post_type : 'page';
    
    // Thanks to blinder for the fix!
    $numberposts = empty($wpsdk_api->query->count) ? -1 : $wpsdk_api->query->count;
    $wp_posts = get_posts(array(
      'post_type' => $post_type,
      'post_parent' => 0,
      'order' => 'ASC',
      'orderby' => 'menu_order',
      'numberposts' => $numberposts
    ));
    foreach ($wp_posts as $wp_post) {
      $pages[] = new WPSDK_Post($wp_post);
    }
    foreach ($pages as $page) {
      $wpsdk_api->introspector->attach_child_posts($page);
    }
    return array(
      'pages' => $pages
    );
  }
  
  public function get_nonce() {
    global $wpsdk_api;
    extract($wpsdk_api->query->get(array('controller', 'method')));
    if ($controller && $method) {
      $controller = strtolower($controller);
      if (!in_array($controller, $wpsdk_api->get_controllers())) {
        $wpsdk_api->error("Unknown controller '$controller'.");
      }
      require_once $wpsdk_api->controller_path($controller);
      if (!method_exists($wpsdk_api->controller_class($controller), $method)) {
        $wpsdk_api->error("Unknown method '$method'.");
      }
      $nonce_id = $wpsdk_api->get_nonce_id($controller, $method);
      return array(
        'controller' => $controller,
        'method' => $method,
        'nonce' => wp_create_nonce($nonce_id)
      );
    } else {
      $wpsdk_api->error("Include 'controller' and 'method' vars in your request.");
    }
  }
  
  protected function get_object_posts($object, $id_var, $slug_var) {
    global $wpsdk_api;
    $object_id = "{$type}_id";
    $object_slug = "{$type}_slug";
    extract($wpsdk_api->query->get(array('id', 'slug', $object_id, $object_slug)));
    if ($id || $$object_id) {
      if (!$id) {
        $id = $$object_id;
      }
      $posts = $wpsdk_api->introspector->get_posts(array(
        $id_var => $id
      ));
    } else if ($slug || $$object_slug) {
      if (!$slug) {
        $slug = $$object_slug;
      }
      $posts = $wpsdk_api->introspector->get_posts(array(
        $slug_var => $slug
      ));
    } else {
      $wpsdk_api->error("No $type specified. Include 'id' or 'slug' var in your request.");
    }
    return $posts;
  }
  
  protected function posts_result($posts) {
    global $wp_query;
    return array(
      'count' => count($posts),
      'count_total' => (int) $wp_query->found_posts,
      'pages' => $wp_query->max_num_pages,
      'posts' => $posts
    );
  }
  
  protected function posts_object_result($posts, $object) {
    global $wp_query;
    // Convert something like "WPSDK_Category" into "category"
    $object_key = strtolower(substr(get_class($object), 6));
    return array(
      'count' => count($posts),
      'pages' => (int) $wp_query->max_num_pages,
      $object_key => $object,
      'posts' => $posts
    );
  }
  
}

?>
