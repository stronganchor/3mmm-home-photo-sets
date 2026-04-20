<?php
/**
 * Plugin Name: 3MMM Home Photo Sets
 * Plugin URI: https://github.com/stronganchor/3mmm-home-photo-sets
 * Description: Replaces the homepage ministry carousel with structured photo sets and a cleaner, captioned gallery.
 * Version: 1.0.1
 * Update URI: https://github.com/stronganchor/3mmm-home-photo-sets
 * Author: Strong Anchor Tech
 * Author URI: https://github.com/stronganchor/3mmm-home-photo-sets
 * License: GPL-2.0-or-later
 */

if (! defined('ABSPATH')) {
	exit;
}

define('MMM_HOME_PHOTO_SETS_VERSION', '1.0.1');
define('MMM_HOME_PHOTO_SETS_FILE', __FILE__);
define('MMM_HOME_PHOTO_SETS_DIR', plugin_dir_path(__FILE__));
define('MMM_HOME_PHOTO_SETS_URL', plugin_dir_url(__FILE__));

function mmm_home_photo_sets_get_update_branch() {
	$branch = 'main';

	if (defined('MMM_HOME_PHOTO_SETS_UPDATE_BRANCH') && is_string(MMM_HOME_PHOTO_SETS_UPDATE_BRANCH)) {
		$override = trim(MMM_HOME_PHOTO_SETS_UPDATE_BRANCH);
		if ($override !== '') {
			$branch = $override;
		}
	}

	return (string) apply_filters('mmm_home_photo_sets_update_branch', $branch);
}

function mmm_home_photo_sets_bootstrap_update_checker() {
	$checker_file = MMM_HOME_PHOTO_SETS_DIR . 'plugin-update-checker/plugin-update-checker.php';
	if (! file_exists($checker_file)) {
		return;
	}

	require_once $checker_file;

	if (! class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
		return;
	}

	$repo_url = (string) apply_filters(
		'mmm_home_photo_sets_update_repository',
		'https://github.com/stronganchor/3mmm-home-photo-sets'
	);
	$slug = dirname(plugin_basename(__FILE__));

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repo_url,
		__FILE__,
		$slug
	);

	$update_checker->setBranch(mmm_home_photo_sets_get_update_branch());

	foreach (array('MMM_HOME_PHOTO_SETS_GITHUB_TOKEN', 'STRONGANCHOR_GITHUB_TOKEN', 'ANCHOR_GITHUB_TOKEN') as $constant_name) {
		if (! defined($constant_name) || ! is_string(constant($constant_name))) {
			continue;
		}

		$token = trim((string) constant($constant_name));
		if ($token !== '') {
			$update_checker->setAuthentication($token);
			break;
		}
	}
}

mmm_home_photo_sets_bootstrap_update_checker();

final class MMM_Home_Photo_Sets {
	const VERSION = MMM_HOME_PHOTO_SETS_VERSION;
	const POST_TYPE = 'mmm_photo_set';
	const META_IMAGE_IDS = '_mmm_photo_set_image_ids';
	const SEEDED_OPTION = 'mmm_home_photo_sets_seeded';

	/**
	 * Singleton instance.
	 *
	 * @var MMM_Home_Photo_Sets|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return MMM_Home_Photo_Sets
	 */
	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstraps hooks.
	 */
	private function __construct() {
		add_action('init', array($this, 'register_post_type'));
		add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
		add_action('save_post_' . self::POST_TYPE, array($this, 'save_photo_set_meta'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

		add_shortcode('mmm_photo_sets_gallery', array($this, 'render_gallery_shortcode'));
		add_filter('the_content', array($this, 'replace_legacy_homepage_carousel'), 9);
	}

	/**
	 * Activation callback.
	 */
	public static function activate() {
		$plugin = self::instance();
		$plugin->register_post_type();
		flush_rewrite_rules();
		$plugin->seed_default_sets();
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Registers the photo-set post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __('Photo Sets', 'mmm-home-photo-sets'),
			'singular_name'      => __('Photo Set', 'mmm-home-photo-sets'),
			'add_new'            => __('Add Photo Set', 'mmm-home-photo-sets'),
			'add_new_item'       => __('Add New Photo Set', 'mmm-home-photo-sets'),
			'edit_item'          => __('Edit Photo Set', 'mmm-home-photo-sets'),
			'new_item'           => __('New Photo Set', 'mmm-home-photo-sets'),
			'view_item'          => __('View Photo Set', 'mmm-home-photo-sets'),
			'search_items'       => __('Search Photo Sets', 'mmm-home-photo-sets'),
			'not_found'          => __('No photo sets found.', 'mmm-home-photo-sets'),
			'not_found_in_trash' => __('No photo sets found in Trash.', 'mmm-home-photo-sets'),
			'menu_name'          => __('Photo Sets', 'mmm-home-photo-sets'),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-format-gallery',
				'supports'            => array('title', 'editor', 'excerpt'),
				'capability_type'     => 'post',
				'has_archive'         => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Registers the gallery images meta box.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'mmm-photo-set-images',
			__('Set Images', 'mmm-home-photo-sets'),
			array($this, 'render_images_meta_box'),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the images meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_images_meta_box($post) {
		wp_nonce_field('mmm_photo_set_images', 'mmm_photo_set_images_nonce');

		$image_ids = $this->get_post_image_ids($post->ID);
		?>
		<p><?php esc_html_e('Choose the 3 to 6 photos that belong to this set. The gallery will keep the set together and stop before the global max image limit is exceeded.', 'mmm-home-photo-sets'); ?></p>
		<input
			type="hidden"
			id="mmm-photo-set-image-ids"
			name="mmm_photo_set_image_ids"
			value="<?php echo esc_attr(implode(',', $image_ids)); ?>"
		/>
		<p>
			<button type="button" class="button button-primary mmm-select-images"><?php esc_html_e('Select Images', 'mmm-home-photo-sets'); ?></button>
			<button type="button" class="button mmm-clear-images"><?php esc_html_e('Clear Images', 'mmm-home-photo-sets'); ?></button>
		</p>
		<div class="mmm-image-preview">
			<?php foreach ($image_ids as $image_id) : ?>
				<?php echo wp_get_attachment_image($image_id, 'thumbnail'); ?>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e('Use the title for the set heading, the excerpt for the small line underneath it, and the main editor for any longer description.', 'mmm-home-photo-sets'); ?>
		</p>
		<?php
	}

	/**
	 * Saves photo-set images.
	 *
	 * @param int $post_id Current post ID.
	 */
	public function save_photo_set_meta($post_id) {
		if (! isset($_POST['mmm_photo_set_images_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mmm_photo_set_images_nonce'])), 'mmm_photo_set_images')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$image_ids = array();

		if (isset($_POST['mmm_photo_set_image_ids'])) {
			$raw_ids = explode(',', sanitize_text_field(wp_unslash($_POST['mmm_photo_set_image_ids'])));
			$image_ids = array_values(array_filter(array_map('absint', $raw_ids)));
		}

		if ($image_ids) {
			update_post_meta($post_id, self::META_IMAGE_IDS, $image_ids);
		} else {
			delete_post_meta($post_id, self::META_IMAGE_IDS);
		}
	}

	/**
	 * Loads admin assets for the media picker.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets($hook_suffix) {
		global $post_type;

		if (! in_array($hook_suffix, array('post.php', 'post-new.php'), true) || self::POST_TYPE !== $post_type) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'mmm-home-photo-sets-admin',
			MMM_HOME_PHOTO_SETS_URL . 'assets/admin.css',
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'mmm-home-photo-sets-admin',
			MMM_HOME_PHOTO_SETS_URL . 'assets/admin.js',
			array('jquery'),
			self::VERSION,
			true
		);
	}

	/**
	 * Replaces the brittle homepage carousel shortcode before WPBakery renders it.
	 *
	 * @param string $content Original post content.
	 * @return string
	 */
	public function replace_legacy_homepage_carousel($content) {
		if (! is_singular() || ! is_main_query() || ! in_the_loop()) {
			return $content;
		}

		if (! is_front_page()) {
			return $content;
		}

		if (false === strpos($content, 'vc_images_carousel') || false === strpos($content, 'ministry-photos')) {
			return $content;
		}

		$replacement = '[mmm_photo_sets_gallery max_images="25"]';
		$pattern = '/\[vc_images_carousel\b(?=[^\]]*ministry-photos)[^\]]*\]/';

		return (string) preg_replace($pattern, $replacement, $content, 1);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_gallery_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'max_images' => 25,
			),
			$atts,
			'mmm_photo_sets_gallery'
		);

		$max_images = max(1, absint($atts['max_images']));
		$sets = $this->get_sets_for_gallery($max_images);

		if (empty($sets)) {
			return '';
		}

		wp_enqueue_style(
			'mmm-home-photo-sets',
			MMM_HOME_PHOTO_SETS_URL . 'assets/gallery.css',
			array(),
			self::VERSION
		);
		wp_enqueue_script(
			'mmm-home-photo-sets',
			MMM_HOME_PHOTO_SETS_URL . 'assets/gallery.js',
			array(),
			self::VERSION,
			true
		);

		$instance_id = 'mmm-gallery-' . wp_generate_uuid4();
		$lightbox_items = array();

		ob_start();
		?>
		<div class="mmm-gallery-shell" id="<?php echo esc_attr($instance_id); ?>">
			<div class="mmm-gallery-sets">
				<?php foreach ($sets as $set) : ?>
					<article class="mmm-gallery-set">
						<div class="mmm-gallery-set__copy">
							<p class="mmm-gallery-set__date"><?php echo esc_html(get_the_date('F Y', $set->ID)); ?></p>
							<h3 class="mmm-gallery-set__title"><?php echo esc_html(get_the_title($set)); ?></h3>

							<?php if (has_excerpt($set)) : ?>
								<p class="mmm-gallery-set__excerpt"><?php echo esc_html(get_the_excerpt($set)); ?></p>
							<?php endif; ?>

							<?php if (! empty($set->post_content)) : ?>
								<div class="mmm-gallery-set__content">
									<?php echo wpautop(wp_kses_post($set->post_content)); ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="mmm-gallery-set__grid">
							<?php
							$image_ids = $this->get_post_image_ids($set->ID);
							foreach ($image_ids as $image_id) :
								$thumbnail = wp_get_attachment_image_src($image_id, 'large');
								$full = wp_get_attachment_image_src($image_id, 'full');

								if (! $thumbnail || ! $full) {
									continue;
								}

								$item = array(
									'image'   => $full[0],
									'alt'     => get_post_meta($image_id, '_wp_attachment_image_alt', true),
									'caption' => trim(get_the_title($set) . ' | ' . get_the_title($image_id)),
								);
								$lightbox_index = count($lightbox_items);
								$lightbox_items[] = $item;
								?>
								<a
									class="mmm-gallery-set__item"
									href="<?php echo esc_url($full[0]); ?>"
									data-mmm-lightbox-index="<?php echo esc_attr((string) $lightbox_index); ?>"
								>
									<?php echo wp_get_attachment_image($image_id, 'large', false, array('loading' => 'lazy')); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="mmm-lightbox" hidden>
				<button type="button" class="mmm-lightbox__close" aria-label="<?php esc_attr_e('Close gallery', 'mmm-home-photo-sets'); ?>">&times;</button>
				<button type="button" class="mmm-lightbox__nav mmm-lightbox__nav--prev" aria-label="<?php esc_attr_e('Previous image', 'mmm-home-photo-sets'); ?>">&lsaquo;</button>
				<div class="mmm-lightbox__stage">
					<img src="" alt="" class="mmm-lightbox__image" />
					<p class="mmm-lightbox__caption"></p>
				</div>
				<button type="button" class="mmm-lightbox__nav mmm-lightbox__nav--next" aria-label="<?php esc_attr_e('Next image', 'mmm-home-photo-sets'); ?>">&rsaquo;</button>
			</div>
		</div>
		<script type="application/json" class="mmm-lightbox-data"><?php echo wp_json_encode($lightbox_items); ?></script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns the gallery sets while keeping each set intact.
	 *
	 * @param int $max_images Maximum images to show in total.
	 * @return WP_Post[]
	 */
	private function get_sets_for_gallery($max_images) {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if (! $query->have_posts()) {
			return array();
		}

		$selected = array();
		$image_count = 0;

		foreach ($query->posts as $set) {
			$set_image_ids = $this->get_post_image_ids($set->ID);
			$set_count = count($set_image_ids);

			if (! $set_count) {
				continue;
			}

			if (! empty($selected) && ($image_count + $set_count) > $max_images) {
				break;
			}

			$selected[] = $set;
			$image_count += $set_count;

			if ($image_count >= $max_images) {
				break;
			}
		}

		return $selected;
	}

	/**
	 * Returns image IDs for a photo set.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function get_post_image_ids($post_id) {
		$image_ids = get_post_meta($post_id, self::META_IMAGE_IDS, true);

		if (! is_array($image_ids)) {
			return array();
		}

		return array_values(array_filter(array_map('absint', $image_ids)));
	}

	/**
	 * Seeds the existing homepage image sets so the new gallery is immediately populated.
	 */
	private function seed_default_sets() {
		if (get_option(self::SEEDED_OPTION)) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if (! empty($existing)) {
			update_option(self::SEEDED_OPTION, 1);
			return;
		}

		$default_sets = array(
			array(
				'post_title'   => 'Final Call Ministries',
				'post_excerpt' => '"Big Community Give Away" | South Boston, VA and Danville, VA',
				'post_content' => 'Hundreds helped through this community give-away outreach.',
				'post_date'    => '2026-04-01 11:06:08',
				'images'       => array(9136, 9135, 9134, 9132, 9133),
			),
			array(
				'post_title'   => 'Pastor Philemon Abraham, Newly Elected Director',
				'post_excerpt' => 'Western North Ghana Conference | February 2026',
				'post_content' => 'Global Missions, Muslim Relations, and Voice of Prophecy leadership update.',
				'post_date'    => '2026-02-10 09:47:54',
				'images'       => array(9056, 9057),
			),
			array(
				'post_title'   => 'Turn to the Widows with Hope',
				'post_excerpt' => 'Sefwi Asawinso, Ghana | December 20, 2025',
				'post_content' => 'Pastor Abraham Philemon, Maranatha SDA Church. 3MMM collaborated with Asawinso SDA Church for this event.',
				'post_date'    => '2026-01-07 13:59:37',
				'images'       => array(9028, 9032, 9029, 9027),
			),
			array(
				'post_title'   => "Teaching Children's Ministry",
				'post_excerpt' => 'Mwanza, Tanzania | October 2024',
				'post_content' => 'Christian Assembly Church and Pastor Ondyek.',
				'post_date'    => '2024-11-04 13:55:47',
				'images'       => array(8335, 8336),
			),
			array(
				'post_title'   => 'July Evangelism',
				'post_excerpt' => 'Baptized several young persons',
				'post_content' => '',
				'post_date'    => '2024-08-16 12:08:00',
				'images'       => array(6704, 6706),
			),
			array(
				'post_title'   => 'Pastor Philemon Abraham',
				'post_excerpt' => '15 baptisms in his 13 church district | Sefi, Ghana, 2024',
				'post_content' => '',
				'post_date'    => '2024-01-03 08:29:50',
				'images'       => array(4384, 4386, 4385, 4387),
			),
			array(
				'post_title'   => 'New Berea Church, Asawinso, Ghana',
				'post_excerpt' => 'Elder Elijah',
				'post_content' => 'Elder Elijah is shown in yellow and serves as conference treasurer and central church pastor.',
				'post_date'    => '2023-08-08 09:24:31',
				'images'       => array(4138),
			),
		);

		foreach ($default_sets as $set) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $set['post_title'],
					'post_excerpt' => $set['post_excerpt'],
					'post_content' => $set['post_content'],
					'post_date'    => $set['post_date'],
					'post_name'    => sanitize_title($set['post_title']),
				),
				true
			);

			if (is_wp_error($post_id)) {
				continue;
			}

			update_post_meta($post_id, self::META_IMAGE_IDS, $set['images']);
		}

		update_option(self::SEEDED_OPTION, 1);
	}
}

MMM_Home_Photo_Sets::instance();
register_activation_hook(__FILE__, array('MMM_Home_Photo_Sets', 'activate'));
register_deactivation_hook(__FILE__, array('MMM_Home_Photo_Sets', 'deactivate'));
