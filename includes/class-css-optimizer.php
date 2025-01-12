<?php
/**
 * Main CSS Optimizer class
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-css-processor.php';
require_once plugin_dir_path(__FILE__) . 'class-css-settings.php';
require_once plugin_dir_path(__FILE__) . 'class-custom-css-manager.php';

class CSSOptimizer {
    private $options;
    private $cache_dir;
    private $css_processor;
    private $settings;
    private $custom_css;
    
    public function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/css-optimizer/';
        $this->init_options();
        
        $this->css_processor = new CSSProcessor($this->options);
        $this->settings = new CSSSettings($this->options);
        $this->custom_css = new CustomCSSManager();
        
        add_action('wp_enqueue_scripts', [$this, 'start_optimization'], 999);
        add_action('wp_head', [$this->custom_css, 'output_custom_css'], 999);
        register_activation_hook(CSS_OPTIMIZER_PLUGIN_FILE, [$this, 'activate']);
    }

    private function init_options() {
        $default_options = [
            'enabled' => true,
            'excluded_urls' => [],
            'preserve_media_queries' => true,
            'exclude_font_awesome' => true,
            'excluded_classes' => [],
            'custom_css' => ''
        ];
        
        $saved_options = get_option('css_optimizer_options', []);
        $this->options = wp_parse_args($saved_options, $default_options);
        update_option('css_optimizer_options', $this->options);
    }

    public function activate() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
        $this->init_options();
    }

    public function start_optimization() {
        if (!$this->options['enabled'] || is_admin()) {
            return;
        }
        $this->css_processor->process_styles();
    }
}