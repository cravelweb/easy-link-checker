<?php

/**
 * Plugin Name: Easy Link Checker
 * Description: WordPress plugin to manually check for broken links (internal & external) in selected posts from the admin panel.
 * Version: 1.0.3
 * Author: Cravel
 * Author URI: https://cravelweb.com
 * Donate link: https://www.buymeacoffee.com/cravel
 */

if (!defined('ABSPATH')) {
  exit;
}

define('MLC_VERSION', '1.0.3');

class Easy_Link_Checker
{

  /**
   * コンストラクタ
   */
  public function __construct()
  {
    // 管理メニューページを追加
    add_action('admin_menu', [$this, 'mlc_add_admin_page']);

    // リンクチェック用のAJAXアクションを追加
    add_action('wp_ajax_mlc_check_links', [$this, 'mlc_check_links']);

    // 管理画面用のスクリプトを登録
    add_action('admin_enqueue_scripts', [$this, 'mlc_enqueue_scripts']);

    // 管理画面用のスタイルを登録
    add_action('admin_enqueue_scripts', [$this, 'mlc_enqueue_styles']);

    // プラグインのテキストドメインをロード
    add_action('plugins_loaded', [$this, 'mlc_load_textdomain']);
  }

  /**
   * テキストドメインをロードする関数
   */
  public function mlc_load_textdomain()
  {
    load_plugin_textdomain('easy-link-checker', false, dirname(plugin_basename(__FILE__)) . '/languages');
  }

  /**
   * 管理画面用のスクリプトを登録
   */
  public function mlc_enqueue_scripts($hook)
  {
    if ($hook != 'toplevel_page_easy-link-checker') return;
    wp_enqueue_script('mlc-admin', plugin_dir_url(__FILE__) . 'js/admin.js', ['jquery'], MLC_VERSION, true);
    wp_localize_script('mlc-admin', 'mlc_vars', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('mlc_nonce'),
      'i18n' => [
        'checking' => __('Checking...', 'easy-link-checker'),
        'please_select' => __('Please select at least one post to check.', 'easy-link-checker'),
        'no_links' => __('No Links', 'easy-link-checker'),
        'broken_links' => __('Broken Links', 'easy-link-checker'),
        'skipped' => __('Skipped', 'easy-link-checker'),
        'valid_links' => __('Valid Links', 'easy-link-checker'),
        'redirected_links' => __('Redirected Links', 'easy-link-checker'),
        'broken_images' => __('Broken Images', 'easy-link-checker'),
        'valid_images' => __('Valid Images', 'easy-link-checker'),
        'failed_to_check' => __('Failed to check links. Please try again.', 'easy-link-checker'),
        'redirected_times' => __('Redirected %s times', 'easy-link-checker'),
      ],
    ]);
  }

  /**
   * 管理画面用のスタイルを登録
   */
  public function mlc_enqueue_styles($hook)
  {
    if ($hook != 'toplevel_page_easy-link-checker') return;
    wp_enqueue_style('mlc-admin', plugin_dir_url(__FILE__) . 'css/admin.css', [], MLC_VERSION, 'all');
  }

  /**
   * 管理メニューページを追加
   */
  public function mlc_add_admin_page()
  {
    add_menu_page(
      __('Easy Link Checker', 'easy-link-checker'), // ページタイトル
      __('Link Checker', 'easy-link-checker'), // メニュータイトル
      'manage_options', // 権限
      'easy-link-checker', // スラッグ
      [$this, 'mlc_admin_page'], // コールバック関数
      'dashicons-admin-links' // アイコン
    );
  }

  /**
   * 管理メニューページのコンテンツ
   */
  public function mlc_admin_page()
  {
    // パラメータを取得
    $post_type = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'post';
    $posts_per_page = isset($_GET['posts_per_page']) ? intval($_GET['posts_per_page']) : 20;
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : ['publish', 'private'];

    // 投稿月
    $post_month = isset($_GET['m']) ? sanitize_text_field($_GET['m']) : '';
    // カテゴリ
    $post_category = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
    // 検索キーワード
    $s = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';


    // 投稿を取得
    $args = [
      'post_type' => $post_type,
      'posts_per_page' => $posts_per_page,
      'paged' => $current_page,
      'orderby' => 'ID',
      'order' => 'DESC',
      'post_status' => $post_status,
    ];

    if (!empty($post_month)) {
      $args['date_query'] = [
        [
          'year' => substr($post_month, 0, 4),
          'month' => substr($post_month, 4),
        ]
      ];
    }

    if (!empty($post_category)) {
      $args['tax_query'] = [
        [
          'taxonomy' => (get_object_taxonomies($post_type)) ? get_object_taxonomies($post_type)[0] : 'category',
          'field' => 'term_id',
          'terms' => $post_category,
        ]
      ];
    }

    if (!empty($s)) {
      $args['s'] = $s;
    }

    $posts = get_posts($args);
    $total_posts = wp_count_posts($post_type)->publish;
    $total_pages = ceil($total_posts / $posts_per_page);
?>
    <div class="wrap">
      <h1><?php _e('Easy Link Checker', 'easy-link-checker'); ?></h1>
      <form id="mlc-post-type-form" method="get">
        <input type="hidden" name="page" value="easy-link-checker">
        <label for="post_type"><?php _e('Select Post Type:', 'easy-link-checker'); ?></label>
        <select id="post_type" name="post_type">
          <?php
          $post_types = get_post_types(['public' => true], 'objects');
          foreach ($post_types as $pt) {
            // メディアライブラリを除外
            if ($pt->name == 'attachment') {
              continue;
            }
            echo '<option value="' . esc_attr($pt->name) . '"' . selected($post_type, $pt->name, false) . '>' . esc_html($pt->label) . '</option>';
          }
          ?>
        </select>
        <label for="posts_per_page"><?php _e('Posts Per Page:', 'easy-link-checker'); ?></label>
        <select id="posts_per_page" name="posts_per_page">
          <option value="20" <?php selected($posts_per_page, 20); ?>>20</option>
          <option value="50" <?php selected($posts_per_page, 50); ?>>50</option>
          <option value="100" <?php selected($posts_per_page, 100); ?>>100</option>
          <option value="150" <?php selected($posts_per_page, 150); ?>>150</option>
          <option value="200" <?php selected($posts_per_page, 200); ?>>200</option>
          <option value="300" <?php selected($posts_per_page, 300); ?>>300</option>
          <option value="500" <?php selected($posts_per_page, 500); ?>>500</option>
        </select>
        <button type="submit" class="button button-primary"><?php _e('Load Posts', 'easy-link-checker'); ?></button>
      </form>

      <form id="mlc-form" method="get">
        <?php
        if (!empty($post_month)) {
          echo '<input type="hidden" name="m" value="' . esc_attr($post_month) . '">';
        }
        if (!empty($post_category)) {
          echo '<input type="hidden" name="cat" value="' . esc_attr($post_category) . '">';
        }
        if (!empty($post_status) && !is_array($post_status)) {
          echo '<input type="hidden" name="post_status" value="' . esc_attr($post_status) . '">';
        } elseif (!empty($post_status) && is_array($post_status)) {
          foreach ($post_status as $status) {
            echo '<input type="hidden" name="post_status[]" value="' . esc_attr($status) . '">';
          }
        }
        ?>
        <input type="hidden" name="page" value="easy-link-checker">
        <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
        <input type="hidden" name="posts_per_page" value="<?php echo esc_attr($posts_per_page); ?>">
        <input type="hidden" name="paged" value="<?php echo esc_attr($current_page); ?>">

        <div id="mlc-posts-list">
          <?php
          $this->mlc_render_status_links($post_status, $post_type, $posts_per_page);
          if (!empty($posts)) :
            $this->mlc_render_filters($post_type, $posts_per_page, $post_month, $post_category, $total_posts, $total_pages, $current_page, $s);
          ?>
            <table class="wp-list-table widefat fixed striped posts">
              <thead>
                <tr>
                  <th scope="col" class="manage-column column-cb check-column"><input type="checkbox" /></th>
                  <th scope="col" class="manage-column" style="width: 6%"><?php _e('ID', 'easy-link-checker'); ?></th>
                  <th scope="col" class="manage-column" style="width: auto!important;"><?php _e('Title', 'easy-link-checker'); ?></th>
                  <th scope="col" class="manage-column" style="width: 8%"><?php _e('Status', 'easy-link-checker'); ?></th>
                  <th scope="col" class="manage-column" style="width: 12%"><?php _e('Published Date', 'easy-link-checker'); ?></th>
                  <th scope="col" class="manage-column" style="width: 12%"><?php _e('Modified Date', 'easy-link-checker'); ?></th>
                  <th scope="col" class="manage-column"><?php _e('Link Check Status', 'easy-link-checker'); ?></th>
                </tr>
              </thead>
              <tbody id="the-list">
                <?php
                $statuses = [
                  'all' => __('All', 'default'),
                  'publish' => __('Published', 'default'),
                  'private' => __('Private', 'default'),
                  'draft' => __('Draft', 'default'),
                  'pending' => __('Pending', 'default'),
                  'trash' => __('Trash', 'default'),
                ];

                foreach ($posts as $post) :
                ?>
                  <tr id="post-<?= esc_attr($post->ID); ?>" data-post-id="<?= esc_attr($post->ID); ?>" class="iedit author-self level-0 post-<?= esc_attr($post->ID); ?> type-post status-publish format-standard hentry category-uncategorized">
                    <th scope="row" class="check-column"><input type="checkbox" name="mlc_post_ids[]" value="<?= esc_attr($post->ID); ?>"></th>
                    <td class="post-id"><a href="<?= esc_attr(get_edit_post_link($post->ID)); ?>" target="_blank"><?= esc_html($post->ID); ?></a></td>
                    <td class="post-title"><strong><a href="<?= esc_attr(get_permalink($post->ID)); ?>" target="_blank"><?= esc_html($post->post_title); ?></a></strong></td>
                    <td class="post-status"><?= esc_html($statuses[$post->post_status]); ?></td>
                    <td class="post-published-date"><?= esc_html(get_the_date('', $post)) . ' ' . esc_html(get_the_time('', $post)); ?></td>
                    <td class="post-modified-date"><?= esc_html(get_the_modified_date('', $post)) . ' ' . esc_html(get_the_modified_time('', $post)); ?></td>
                    <td class="post-link-check-status"><span class="link-check-status" data-post-id="<?= esc_attr($post->ID); ?>"><?php _e('Not Checked', 'easy-link-checker'); ?></span></td>
                  </tr>
                <?php
                endforeach;
                ?>
              </tbody>
            </table>
          <?php
          else :
          ?>
            <div style="clear: both;">
              <p><?php _e('No posts found.', 'easy-link-checker'); ?></p>
            </div>
          <?php
          endif;
          ?>
        </div>
      </form>

      <div class="mlc-check-options">
        <label for="check_links"><input type="checkbox" id="check_links" name="check_links" value="1" checked> <?php _e('Check Links', 'easy-link-checker'); ?></label>
        <label for="check_internal_only"><input type="checkbox" id="check_internal_only" name="check_internal_only" value="1"> <?php _e('Check Internal Links Only', 'easy-link-checker'); ?></label>
        <label for="check_images"><input type="checkbox" id="check_images" name="check_images" value="1"> <?php _e('Check Images', 'easy-link-checker'); ?></label>
      </div>

      <div>
        <button type="button" id="mlc-check-links" class="button button-primary"><?php _e('Start Check', 'easy-link-checker'); ?></button> <span id="mlc-spinner" style="display: none;"><img src="<?php echo admin_url('images/spinner.gif'); ?>" alt="<?php esc_attr_e('Loading...', 'easy-link-checker'); ?>"></span>
      </div>

    </div>
<?php
  }

  /**
   * 投稿ステータス絞り込みリンクを表示する関数
   */
  public function mlc_render_status_links($post_status, $post_type, $posts_per_page)
  {
    $base_link = admin_url('admin.php?page=easy-link-checker&post_type=' . $post_type . '&posts_per_page=' . $posts_per_page);

    // 投稿ステータスのフィルタリングリンク
    echo '<div class="subsubsub">';
    $status_links = [];
    $statuses = [
      'all' => __('All', 'default'),
      'publish' => __('Published', 'default'),
      'private' => __('Private', 'default'),
      'draft' => __('Draft', 'default'),
      'pending' => __('Pending', 'default'),
      'trash' => __('Trash', 'default'),
    ];

    foreach ($statuses as $status_key => $status_label) {
      // 投稿数を取得
      if ($status_key == 'all') {
        $status_count = wp_count_posts($post_type)->publish + wp_count_posts($post_type)->private + wp_count_posts($post_type)->draft + wp_count_posts($post_type)->pending + wp_count_posts($post_type)->trash;
      } else {
        $status_count = wp_count_posts($post_type)->$status_key;
      }

      $active_class = ($post_status == $status_key) ? ' class="current"' : '';
      $strong_start = ($post_status == $status_key) ? '<strong>' : '';
      $strong_end = ($post_status == $status_key) ? '</strong>' : '';
      $status_links[] = '<a href="' . $base_link . '&post_status=' . $status_key . '"' . $active_class . '>' . $strong_start . $status_label . $strong_end . '</a> <span class="count">(' . $status_count . ')</span>';
    }

    echo implode(' | ', $status_links);
    echo '</div>';
  }

  /**
   * 投稿のフィルタリングオプションを表示する関数
   */
  public function mlc_render_filters($post_type, $posts_per_page, $post_month, $post_category, $total_posts, $total_pages, $current_page, $s = '')
  {
    $base_link = admin_url('admin.php?page=easy-link-checker&post_type=' . $post_type . '&posts_per_page=' . $posts_per_page);

    echo '<div class="tablenav top">';
    echo '<div class="alignleft actions">';

    // 月のフィルタリング
    $months = $this->mlc_get_post_months($post_type);
    if (!empty($months)) {
      echo '<select id="m" name="m">';
      echo '<option value="">' . __('All dates', 'default') . '</option>';
      foreach ($months as $month) {
        $month_timestamp = mktime(0, 0, 0, $month->month, 1, $month->year);
        $formatted_month = date_i18n(__('F Y', 'easy-link-checker'), $month_timestamp);
        echo '<option value="' . esc_attr($month->year . sprintf('%02d', $month->month)) . '"' . selected($post_month, $month->year . sprintf('%02d', $month->month), false) . '>' . esc_html($formatted_month) . '</option>';
      }
      echo '</select>';
    }

    // カテゴリのフィルタリング
    // 対象の投稿タイプがカテゴリをサポートしている場合のみ
    $taxonomies = get_object_taxonomies($post_type, 'objects');
    $taxonomy = null;
    foreach ($taxonomies as $tax) {
      if ($tax->hierarchical) {
        $taxonomy = $tax->name;
        break;
      }
    }

    if ($taxonomy) {
      $categories = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,  // trueに設定すると、関連する投稿がないタームを隠します。
        'orderby' => 'name',
        'order' => 'ASC',
        'exclude' => get_terms([
          'taxonomy' => $taxonomy,
          'hide_empty' => false,
          'fields' => 'ids',
          'meta_query' => [
            [
              'key' => '_delete_flag',
              'value' => 1,
              'compare' => '!='
            ]
          ]
        ]),
      ]);


      if (!empty($categories)) {
        echo '<select id="cat" name="cat">';
        echo '<option value="0">' . __('All Categories', 'default') . '</option>';
        $this->mlc_render_category_options($categories, 0, $post_category);
        echo '</select>';
      }
    }

    // 検索ボックス
    echo ' <input type="search" id="post-search-input" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Keywords', 'default') . '" />';

    // 絞り込みボタン
    echo ' <button type="submit" class="button">' . __('Filter', 'default') . '</button>';
    echo '</div>';

    // ページネーション
    if ($total_pages > 1) {
      echo '<div class="tablenav-pages">
              <span class="displaying-num">' . sprintf(__('%s items', 'easy-link-checker'), $total_posts) . '</span>
              <span class="pagination-links">';

      // 「<<」最初のページへのリンク
      if ($current_page > 1) {
        echo '<a class="first-page button" href="' . $base_link . '&paged=1">&laquo;</a> ';
      } else {
        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span> ';
      }

      // 「<」前のページへのリンク
      if ($current_page > 1) {
        echo '<a class="prev-page button" href="' . $base_link . '&paged=' . ($current_page - 1) . '">&lsaquo;</a> ';
      } else {
        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span> ';
      }

      // 現在のページ
      echo '<span class="paging-input"><input class="current-page" type="text" name="paged" value="' . $current_page . '" size="1" aria-describedby="table-paging"> ';
      echo ' / <span class="total-pages">' . $total_pages . '</span></span> ';

      // 「>」次のページへのリンク
      if ($current_page < $total_pages) {
        echo '<a class="next-page button" href="' . $base_link . '&paged=' . ($current_page + 1) . '">&rsaquo;</a> ';
      } else {
        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span> ';
      }

      // 「>>」最後のページへのリンク
      if ($current_page < $total_pages) {
        echo '<a class="last-page button" href="' . $base_link . '&paged=' . $total_pages . '">&raquo;</a>';
      } else {
        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
      }

      echo '</span></div>';
    }
    echo '</div>';
  }


  /**
   * カテゴリオプションを再帰的に表示する関数
   */
  public function mlc_render_category_options($categories, $depth = 0, $selected_category = 0)
  {
    // まず、親カテゴリを抽出します
    $top_level_categories = array_filter($categories, function ($cat) {
      return $cat->parent == 0;  // 親カテゴリがない（＝トップレベル）のものを取得
    });

    // 親カテゴリから再帰的にオプションをレンダリングします
    foreach ($top_level_categories as $top_level_category) {
      $this->mlc_render_single_category_option($top_level_category, $categories, $depth, $selected_category);
    }
  }

  /**
   * 個々のカテゴリオプションを再帰的にレンダリングする関数
   */
  private function mlc_render_single_category_option($category, $all_categories, $depth, $selected_category)
  {
    $indent = str_repeat('&nbsp;&nbsp;', $depth);
    $is_selected = selected($category->term_id, $selected_category, false);
    echo '<option value="' . esc_attr($category->term_id) . '"' . $is_selected . '>' . $indent . esc_html($category->name) . '</option>';

    // 子カテゴリを探して再帰的に処理
    $child_categories = array_filter($all_categories, function ($cat) use ($category) {
      return $cat->parent == $category->term_id;
    });

    foreach ($child_categories as $child_category) {
      $this->mlc_render_single_category_option($child_category, $all_categories, $depth + 1, $selected_category);
    }
  }



  /**
   * 投稿の月を取得する関数
   */
  public function mlc_get_post_months($post_type)
  {
    global $wpdb;

    $query = "SELECT YEAR(post_date) AS year, MONTH(post_date) AS month
              FROM $wpdb->posts
              WHERE post_type = %s
              AND post_status = 'publish'
              GROUP BY year, month
              ORDER BY year DESC, month DESC";
    $results = $wpdb->get_results($wpdb->prepare($query, $post_type));

    return $results;
  }

  /**
   * リンクをチェックするためのAJAXハンドラ
   */
  public function mlc_check_links()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('Permission denied', 'default')); // 権限がない場合は終了します
    }

    $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
    $check_links = isset($_POST['check_links']) ? filter_var($_POST['check_links'], FILTER_VALIDATE_BOOLEAN) : true;
    $check_internal_only = isset($_POST['check_internal_only']) ? filter_var($_POST['check_internal_only'], FILTER_VALIDATE_BOOLEAN) : true;
    $check_images = isset($_POST['check_images']) ? filter_var($_POST['check_images'], FILTER_VALIDATE_BOOLEAN) : true;

    if (empty($post_ids)) {
      echo json_encode([]); // 投稿が選択されていない場合は空の結果を返す
      wp_die();
    }

    $results = [];
    foreach ($post_ids as $post_id) {
      $post_content = get_post_field('post_content', $post_id); // 投稿の本文を取得します

      if (empty($post_content)) {
        continue; // 投稿の本文が空の場合はスキップします
      }

      $broken_links = [];
      $valid_links = [];
      $skipped_links = [];
      $broken_images = [];
      $valid_images = [];
      $skipped_images = [];

      $links = [];
      $images = [];

      if ($check_links) {
        // リンク（aタグ）のhref属性を抽出
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post_content, $matches);
        $links = $matches[1];

        // リンクをチェック
        foreach ($links as $link) {
          // 内部リンクのみをチェックするかどうかを確認
          if ($check_internal_only && (strpos($link, home_url()) === false && strpos($link, '/') !== 0)) {
            // 外部リンクをスキップする場合
            $skipped_links[] = $link;
            continue;
          }

          // リンクのHTTPヘッダーを取得してチェック
          $response = wp_remote_get($link, [
            'timeout' => 10,
            'redirection' => 5, // 最大5回のリダイレクトを許可
          ]);

          if (is_wp_error($response)) {
            // エラーが発生したリンクはリンク切れとして記録
            $broken_links[] = $link;
          } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $redirect_count = 0;
            if (isset($response['http_response']) && method_exists($response['http_response'], 'get_response')) {
              $http_response = $response['http_response']->get_response();
              if (isset($http_response->history)) {
                $redirect_count = count($http_response->history);
              }
            }

            if ($response_code >= 400) {
              // ステータスコードが400以上の場合はリンク切れとして記録
              $broken_links[] = $link;
            } elseif ($redirect_count > 5) {
              // リダイレクト回数が5回を超える場合はリンク切れとして記録
              $redirected_links[] = [
                'url' => $link,
                'redirects' => $redirect_count,
                'message' => sprintf(__('Redirected %s times', 'easy-link-checker'), $redirect_count),
              ];
            } else {
              $valid_links[] = $link;
            }
          }
        }
      }

      if ($check_images) {
        // 画像（imgタグ）のsrc属性を抽出
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post_content, $img_matches);
        $images = $img_matches[1];

        // 画像をチェック
        foreach ($images as $image) {
          // 内部リンクのみをチェックするかどうかを確認
          if ($check_internal_only && (strpos($image, home_url()) === false && strpos($image, '/') !== 0)) {
            // 外部画像リンクをスキップする場合
            $skipped_images[] = $image;
            continue;
          }

          // 画像のHTTPヘッダーを取得してチェック
          $response = wp_remote_head($image);
          if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            // エラーが発生したリンク、またはステータスコードが400以上の場合はリンク切れとして記録します
            $broken_images[] = $image;
          } else {
            $valid_images[] = $image;
          }
        }
      }

      $results[] = [
        'post_id' => $post_id,
        'links' => $links,
        'broken_links' => $broken_links,
        'valid_links' => $valid_links,
        'redirected_links' => $redirected_links,
        'skipped_links' => $skipped_links,
        'images' => $images,
        'broken_images' => $broken_images,
        'valid_images' => $valid_images,
        'skipped_images' => $skipped_images,
      ];
    }

    echo json_encode($results);
    wp_die();
  }
}

new Easy_Link_Checker();
