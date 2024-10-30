<?php

class WPSDK_Post {
  
  // Note:
  //   WPSDK_Post objects must be instantiated within The Loop.
  
  var $id;              // Integer
  var $type;            // String
  var $slug;            // String
  var $url;             // String
  var $status;          // String ("draft", "published", or "pending")
  var $title;           // String
  var $title_plain;     // String
  var $content;         // String (modified by read_more query var)
  var $excerpt;         // String
  var $date;            // String (modified by date_format query var)
  var $modified;        // String (modified by date_format query var)
  var $categories;      // Array of objects
  var $tags;            // Array of objects
  var $author;          // Object
  var $comments;        // Array of objects
  var $attachments;     // Array of objects
  var $comment_count;   // Integer
  var $comment_status;  // String ("open" or "closed")
  var $thumbnail;       // String
  var $custom_fields;   // Object (included by using custom_fields query var)
  
  function WPSDK_Post($wp_post = null) {
    if (!empty($wp_post)) {
      $this->import_wp_object($wp_post);
    }
    do_action("wpsdk_{$this->type}_constructor", $this);
  }
  
  function create($values = null) {
    unset($values['id']);
    if (empty($values) || empty($values['title'])) {
      $values = array(
        'title' => 'Untitled',
        'content' => ''
      );
    }
    return $this->save($values);
  }
  
  function update($values) {
    $values['id'] = $this->id;
    return $this->save($values);
  }
  
  function save($values = null) {
    global $wpsdk_api, $user_ID;
    
    $wp_values = array();
    
    if (!empty($values['id'])) {
      $wp_values['ID'] = $values['id'];
    }
    
    if (!empty($values['type'])) {
      $wp_values['post_type'] = $values['type'];
    }
    
    if (!empty($values['status'])) {
      $wp_values['post_status'] = $values['status'];
    }
    
    if (!empty($values['title'])) {
      $wp_values['post_title'] = $values['title'];
    }
    
    if (!empty($values['content'])) {
      $wp_values['post_content'] = $values['content'];
    }
    
    if (!empty($values['author'])) {
      $author = $wpsdk_api->introspector->get_author_by_login($values['author']);
      $wp_values['post_author'] = $author->id;
    }
    
    if (isset($values['categories'])) {
      $categories = explode(',', $values['categories']);
      foreach ($categories as $category_slug) {
        $category_slug = trim($category_slug);
        $category = $wpsdk_api->introspector->get_category_by_slug($category_slug);
        if (empty($wp_values['post_category'])) {
          $wp_values['post_category'] = array($category->id);
        } else {
          array_push($wp_values['post_category'], $category->id);
        }
      }
    }
    
    if (isset($values['tags'])) {
      $tags = explode(',', $values['tags']);
      foreach ($tags as $tag_slug) {
        $tag_slug = trim($tag_slug);
        if (empty($wp_values['tags_input'])) {
          $wp_values['tags_input'] = array($tag_slug);
        } else {
          array_push($wp_values['tags_input'], $tag_slug);
        }
      }
    }
    
    if (isset($wp_values['ID'])) {
      $this->id = wp_update_post($wp_values);
    } else {
      $this->id = wp_insert_post($wp_values);
    }
    
    if (!empty($_FILES['attachment'])) {
      include_once ABSPATH . '/wp-admin/includes/file.php';
      include_once ABSPATH . '/wp-admin/includes/media.php';
      include_once ABSPATH . '/wp-admin/includes/image.php';
      $attachment_id = media_handle_upload('attachment', $this->id);
      $this->attachments[] = new WPSDK_Attachment($attachment_id);
      unset($_FILES['attachment']);
    }
    
    $wp_post = get_post($this->id);
    $this->import_wp_object($wp_post);
    
    return $this->id;
  }
  
  function import_wp_object($wp_post) {
    global $wpsdk_api, $post;
    $date_format = $wpsdk_api->query->date_format;
    $this->id = (int) $wp_post->ID;
    setup_postdata($wp_post);
    $this->set_value('type', $wp_post->post_type);
    $this->set_value('slug', $wp_post->post_name);
    $this->set_value('url', get_permalink($this->id));
    $this->set_value('status', $wp_post->post_status);
    $this->set_value('title', html_entity_decode(get_the_title($this->id)));
    $this->set_value('title_plain', html_entity_decode(strip_tags(@$this->title)));
    $this->set_content_value();
    $this->set_value('excerpt', html_entity_decode(wp_trim_words($this->content,80,' ...')));
    $this->set_value('date', get_the_time($date_format));
    $this->set_value('modified', date($date_format, strtotime($wp_post->post_modified)));
    $this->set_categories_value();
    $this->set_tags_value();
    $this->set_author_value($wp_post->post_author);
    $this->set_comments_value();
    $this->set_attachments_value();
    $this->set_value('comment_count', (int) $wp_post->comment_count);
    $this->set_value('comment_status', $wp_post->comment_status);
    $this->set_thumbnail_value();
    $this->set_custom_fields_value();
    $this->set_custom_taxonomies($wp_post->post_type);
    do_action("wpsdk_import_wp_post", $this, $wp_post);
  }
  
  function set_value($key, $value) {
    global $wpsdk_api;
    if ($wpsdk_api->include_value($key)) {
      $this->$key = $value;
    } else {
      unset($this->$key);
    }
  }
    
  function set_content_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('content')) {
      $content = get_the_content($wpsdk_api->query->read_more);
      $content = apply_filters('the_content', $content);
      $content = str_replace(']]>', ']]&gt;', $content);
      $this->content = $content;
    } else {
      unset($this->content);
    }
  }
  
  function set_categories_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('categories')) {
      $this->categories = array();
      if ($wp_categories = get_the_category($this->id)) {
        foreach ($wp_categories as $wp_category) {
          $category = new WPSDK_Category($wp_category);
          if ($category->id == 1 && $category->slug == 'uncategorized') {
            // Skip the 'uncategorized' category
            continue;
          }
          $this->categories[] = $category;
        }
      }
    } else {
      unset($this->categories);
    }
  }
  
  function set_tags_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('tags')) {
      $this->tags = array();
      if ($wp_tags = get_the_tags($this->id)) {
        foreach ($wp_tags as $wp_tag) {
          $this->tags[] = new WPSDK_Tag($wp_tag);
        }
      }
    } else {
      unset($this->tags);
    }
  }
  
  function set_author_value($author_id) {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('author')) {
      $this->author = new WPSDK_Author($author_id);
    } else {
      unset($this->author);
    }
  }
  
  function set_comments_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('comments')) {
      $this->comments = $wpsdk_api->introspector->get_comments($this->id);
    } else {
      unset($this->comments);
    }
  }
  
  function set_attachments_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('attachments')) {
      $this->attachments = $wpsdk_api->introspector->get_attachments($this->id);
    } else {
      unset($this->attachments);
    }
  }
  
  function set_thumbnail_value() {
    global $wpsdk_api;
    if (!$wpsdk_api->include_value('thumbnail') ||
        !function_exists('get_post_thumbnail_id')) {
      unset($this->thumbnail);
      return;
    }
    $attachment_id = get_post_thumbnail_id($this->id);
    if (!$attachment_id) {
      unset($this->thumbnail);
      return;
    }
    $thumbnail_size = $this->get_thumbnail_size();
    $this->thumbnail_size = $thumbnail_size;
    $attachment = $wpsdk_api->introspector->get_attachment($attachment_id);
    $image = $attachment->images[$thumbnail_size];
    $this->thumbnail = $image->url;
    $this->thumbnail_images = $attachment->images;
  }
  
  function set_custom_fields_value() {
    global $wpsdk_api;
    if ($wpsdk_api->include_value('custom_fields')) {
      $wp_custom_fields = get_post_custom($this->id);
      $this->custom_fields = new stdClass();
      if ($wpsdk_api->query->custom_fields) {
        $keys = explode(',', $wpsdk_api->query->custom_fields);
      }
      foreach ($wp_custom_fields as $key => $value) {
        if ($wpsdk_api->query->custom_fields) {
          if (in_array($key, $keys)) {
            $this->custom_fields->$key = $wp_custom_fields[$key];
          }
        } else if (substr($key, 0, 1) != '_') {
          $this->custom_fields->$key = $wp_custom_fields[$key];
        }
      }
    } else {
      unset($this->custom_fields);
    }
  }
  
  function set_custom_taxonomies($type) {
    global $wpsdk_api;
    $taxonomies = get_taxonomies(array(
      'object_type' => array($type),
      'public'   => true,
      '_builtin' => false
    ), 'objects');
    foreach ($taxonomies as $taxonomy_id => $taxonomy) {
      $taxonomy_key = "taxonomy_$taxonomy_id";
      if (!$wpsdk_api->include_value($taxonomy_key)) {
        continue;
      }
      $taxonomy_class = $taxonomy->hierarchical ? 'WPSDK_Category' : 'WPSDK_Tag';
      $terms = get_the_terms($this->id, $taxonomy_id);
      $this->$taxonomy_key = array();
      if (!empty($terms)) {
        $taxonomy_terms = array();
        foreach ($terms as $term) {
          $taxonomy_terms[] = new $taxonomy_class($term);
        }
        $this->$taxonomy_key = $taxonomy_terms;
      }
    }
  }
  
  function get_thumbnail_size() {
    global $wpsdk_api;
    if ($wpsdk_api->query->thumbnail_size) {
      return $wpsdk_api->query->thumbnail_size;
    } else if (function_exists('get_intermediate_image_sizes')) {
      $sizes = get_intermediate_image_sizes();
      if (in_array('post-thumbnail', $sizes)) {
        return 'post-thumbnail';
      }
    }
    return 'thumbnail';
  }
  
}

?>
