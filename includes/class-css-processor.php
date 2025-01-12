<?php
/**
 * CSS Processing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class CSSProcessor {
    private $options;
    
    public function __construct($options) {
        $this->options = $options;
    }

    public function process_styles() {
        global $wp_styles;
        if (!is_object($wp_styles)) {
            return;
        }

        $original_queue = $wp_styles->queue;

        foreach ($original_queue as $handle) {
            // Skip processing if the handle contains 'code-block-pro'
            if (strpos($handle, 'code-block-pro') !== false) {
                continue;
            }

            if (!$this->should_process_style($handle, $wp_styles)) {
                continue;
            }

            $css_content = $this->get_css_content($handle, $wp_styles);
            if (!$css_content) {
                continue;
            }

            $this->process_and_enqueue_style($handle, $css_content, $wp_styles);
        }
    }






  
  
    private function should_process_style($handle, $wp_styles) {
        if (!isset($wp_styles->registered[$handle]) || empty($wp_styles->registered[$handle]->src)) {
            return false;
        }

        // Check if the style is related to Code Block Pro
        if (strpos($handle, 'code-block-pro') !== false || 
            strpos($handle, 'kevinbatdorf') !== false || 
            strpos($handle, 'shiki') !== false) {
            return false;
        }

        return !$this->should_skip($handle);
    }
  
  

      private function should_skip($handle) {
        $skip_handles = [
            'admin-bar', 
            'dashicons',
            'code-block-pro',
            'wp-block-kevinbatdorf-code-block-pro',
            'shiki'
        ];
        
        if ($this->options['exclude_font_awesome']) {
            $font_awesome_handles = ['font-awesome', 'fontawesome', 'fa', 'font-awesome-official'];
            $skip_handles = array_merge($skip_handles, $font_awesome_handles);
        }
        
        // Check if handle contains any of the skip patterns
        foreach ($skip_handles as $skip_handle) {
            if (strpos($handle, $skip_handle) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function get_css_content($handle, $wp_styles) {
        $style = $wp_styles->registered[$handle];
        $src = $this->normalize_url($style->src);
        
        $css_file = $this->get_local_css_path($src);
        if ($css_file && is_file($css_file)) {
            return @file_get_contents($css_file);
        }
        
        return $this->fetch_remote_css($src);
    }

    private function normalize_url($src) {
        if (strpos($src, '//') === 0) {
            return 'https:' . $src;
        } elseif (strpos($src, '/') === 0) {
            return site_url($src);
        }
        return $src;
    }

    private function get_local_css_path($src) {
        $parsed_url = parse_url($src);
        $path = isset($parsed_url['path']) ? ltrim($parsed_url['path'], '/') : '';
        
        $possible_paths = [
            ABSPATH . $path,
            WP_CONTENT_DIR . '/' . str_replace('wp-content/', '', $path),
            get_stylesheet_directory() . '/' . basename($path)
        ];
        
        foreach ($possible_paths as $test_path) {
            $test_path = wp_normalize_path($test_path);
            if (file_exists($test_path) && is_file($test_path)) {
                return $test_path;
            }
        }
        
        return false;
    }

    private function fetch_remote_css($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $response = wp_remote_get($url);
        return !is_wp_error($response) ? wp_remote_retrieve_body($response) : false;
    }

    private function process_and_enqueue_style($handle, $css_content, $wp_styles) {
        $optimized_css = $this->optimize_css($css_content);
        $optimized_css = $this->fix_font_paths($optimized_css, dirname($wp_styles->registered[$handle]->src));

        wp_deregister_style($handle);
        wp_register_style($handle . '-optimized', false);
        wp_enqueue_style($handle . '-optimized');
        wp_add_inline_style($handle . '-optimized', $optimized_css);
    }

      private function optimize_css($css) {
        if ($this->options['preserve_media_queries']) {
            preg_match_all('/@media[^{]+\{([^}]+)\}/s', $css, $media_queries);
            $media_blocks = isset($media_queries[0]) ? $media_queries[0] : [];
        }

        preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $matches);
        
        $optimized = '';
        if (!empty($matches[0])) {
            foreach ($matches[0] as $i => $rule) {
                $selectors = $matches[1][$i];
                
                // Skip optimization for Code Block Pro related selectors
                if (strpos($selectors, 'code-block-pro') !== false ||
                    strpos($selectors, 'wp-block-kevinbatdorf') !== false ||
                    strpos($selectors, 'shiki') !== false ||
                    strpos($selectors, 'cbp-') !== false) {
                    $optimized .= $rule;
                    continue;
                }

                if (strpos($selectors, '@media') === 0) continue;
                
                $optimized_properties = $this->optimize_properties($matches[2][$i]);
                if (!empty($optimized_properties)) {
                    $optimized .= trim($selectors) . '{' . $optimized_properties . '}';
                }
            }
        }

        if ($this->options['preserve_media_queries'] && !empty($media_blocks)) {
            $optimized .= "\n" . implode("\n", $media_blocks);
        }

        return $this->minify_css($optimized);
    }

    private function optimize_properties($properties) {
        $props = array_filter(array_map('trim', explode(';', $properties)));
        $unique_props = [];
        
        foreach ($props as $prop) {
            if (empty($prop)) continue;
            
            $parts = explode(':', $prop, 2);
            if (count($parts) !== 2) continue;
            
            $unique_props[trim($parts[0])] = $prop;
        }

        return implode(';', $unique_props) . ';';
    }

    private function minify_css($css) {
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        $css = str_replace([': ', "\r\n", "\r", "\n", "\t", '{ ', ' {', '} ', ' }', ';}'], [':', '', '', '', '', '{', '{', '}', '}', '}'], $css);
        return trim(preg_replace('/\s+/', ' ', $css));
    }

    private function fix_font_paths($css, $base_url) {
        return preg_replace_callback(
            '/url\([\'"]?(?!data:)([^\'")]+)[\'"]?\)/i',
            function($matches) use ($base_url) {
                $url = $matches[1];
                if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                    $url = trailingslashit($base_url) . ltrim($url, '/');
                }
                return 'url("' . $url . '")';
            },
            $css
        );
    }
}