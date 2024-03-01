<?php
/**
 * Plugin Name: SIT rewrite
 * Description: Lepší adresy pro CPT a taxonomy
 * Version: 1.0.3
 * Author: SIT:Jaroslav Dvořák
 **/

// Cesta k pluginu
if ( !defined('SML_PLUGIN_PATH') ) {
    define( 'SML_PLUGIN_PATH', plugin_dir_url( __FILE__ ) );
}

/*
 * Tvar adresy: /cpt-slug/term-no-parent-slug/post-name/
 * Rewrite u registrace CPT nastaveno na: "cpt-slug/%tax-name%"
 * Musime nahradit vyraz %tax-name% slugem kategorie (term slug)
 * nejvyssi urovne (no parent)
 */
add_filter('post_type_link', 'j3w_update_permalink_structure', 10, 2);

function j3w_update_permalink_structure($post_link, $post)
{
    $matches = j3w_helper_match_rewrite_slug($post_link);

    if (!empty($matches) && false !== strpos($post_link, $matches[0])) {

        $taxonomy_name = str_replace("%", "", $matches[0]);
        $taxonomy_terms = get_the_terms($post->ID, $taxonomy_name);

        if (!empty($taxonomy_terms) && !is_wp_error($taxonomy_terms)) {
            foreach ($taxonomy_terms as $term) {

                // Pokud najdeme primarni kategorii z Yoast SEO plauginu
                $primary_term = j3w_get_primary_taxonomy_term($post->ID, $taxonomy_name);
                if ( $primary_term ) {
                    $term_slug = $primary_term["slug"];
                }
                // V pripade ze nema rodice, pouzijem
                elseif (!$term->parent) {
                    $term_slug = $term->slug;
                } // Jinak musime najit roota :)
                else {
                    $ancestors = get_ancestors($term->term_id, $taxonomy_name);
                    $root_id = end($ancestors);
                    $root = get_term($root_id, $taxonomy_name);
                    $term_slug = $root->slug;
                }

                $post_link = str_replace($matches[0], $term_slug, $post_link);
            }
        }
    }

    return $post_link;
}


/*
 * Kdyz pouzijeme ten trik s rewrite tagem %tax-name%
 * Prestane fungovat rewrite 'hierarchical' => true u taxonomy
 * Musime pro ty dalsi urovne pridat rewrite rules
 * Pokud nekdo pouzije uz nadefinovane rewrite slugy, ma smulu :) Nepouzivame to.
 * Stejne tak pro vsechny terms musime pridat rewrites pro strankovani
 * Pokud chceme mit jiny tvar nez "page" jako v nasem pripade
 */
add_filter('generate_rewrite_rules', 'j3w_taxonomy_slug_rewrite');

function j3w_taxonomy_slug_rewrite($wp_rewrite)
{
    $rules = array();
    // get all custom taxonomies
    $taxonomies = get_taxonomies(array('_builtin' => false), 'objects');
    // get all custom post types
    $post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');

    foreach ($post_types as $post_type) {

        foreach ($taxonomies as $taxonomy) {

            // go through all post types which this taxonomy is assigned to
            foreach ($taxonomy->object_type as $object_type) {

                // check if taxonomy is registered for this custom type
                if ($object_type == $post_type->name) {

                    // terms
                    $terms = get_categories(array('type' => $object_type, 'taxonomy' => $taxonomy->name, 'hide_empty' => 0));
                    // make rules
                    foreach ($terms as $term) {

                        // Resime jen pro CPT s rewrite tagem %tax-name%
                        // Pokud nekdo pouzije uz nadefinovane rewrite slugy, ma smulu :) Nepouzivame to.
                        $matches = j3w_helper_match_rewrite_slug($post_type->rewrite['slug']);

                        // Pokud najdeme a zaroven se to rovna (to se bude rovnat asi vzdy ze? :)
                        if (!empty($matches) && false !== strpos($post_type->rewrite['slug'], $matches[0])) {

                            $parents_path = "";

                            // Resime pouze pro terms, ktere maji rodice :)
                            // Pro no parent to funguje i bez toho
                            if ($term->parent) {

                                $path = j3w_get_ancestors_path($term->term_id, $taxonomy->name);
                                $term_path = str_replace($matches[0], $path . $term->slug, $post_type->rewrite['slug']);

                                // Rewrite pro zanorene urovne - no parent
                                $rules[$term_path . '/?$'] = 'index.php?' . $term->taxonomy . '=' . $term->slug;
                            } else {
                                $term_path = str_replace($matches[0], $term->slug, $post_type->rewrite['slug']);
                            }
                            // Pravidla pro strankovani - misto "page" - "strana"
                            $rules[$term_path . '/' . __('strana') . '/?([0-9]{1,})/?$'] = 'index.php?' . $term->taxonomy . '=' . $term->slug . '&paged=$matches[1]';
                        }
                    }
                }
            }
        }
    }

    // merge with global rules
    $wp_rewrite->rules = $rules + $wp_rewrite->rules;

    return $wp_rewrite->rules;
}

/*
 * Pro CPT archive musime pridat pravidla
 * aby strankovani ve tvaru "/strana/n/" fungovalo
 */
add_filter('generate_rewrite_rules', 'j3w_post_type_archive_pagination_rewrite');

function j3w_post_type_archive_pagination_rewrite($wp_rewrite)
{
    // get all custom post types
    $post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');

    foreach ($post_types as $post_type) {
        // Strankovani pro post_type archive
        // Menime slug "page" na "strana"
        $matches = j3w_helper_match_rewrite_slug($post_type->rewrite['slug']);
        // Pokud obsahuje string ve tvaru %tax-name%
        if (!empty($matches)) {
            $post_type_slug = str_replace("/" . $matches[0], "", $post_type->rewrite['slug']);
            $rules[$post_type_slug . '/' . __('strana') . '/([0-9]{1,})/?$'] = 'index.php?post_type=' . $post_type->name . '&paged=$matches[1]';
        } else {
            // Jinak rewrite zustava, ale pridame tam jen to "strana" pro strankovani
            $rules[$post_type->rewrite['slug'] . '/' . __('strana') . '/([0-9]{1,})/?$'] = 'index.php?post_type=' . $post_type->name . '&paged=$matches[1]';
        }
    }

    // merge with global rules
    $wp_rewrite->rules = $rules + $wp_rewrite->rules;

    return $wp_rewrite->rules;
}


/*
 * Zmena nazvu strankovani
 * Misto "page" chceme "strana"
 */
add_action('init', 'j3w_change_page_rewrite_slug');

function j3w_change_page_rewrite_slug()
{
    $GLOBALS['wp_rewrite']->pagination_base = __('strana');
}


/*
 * HELEPERS
 */
// Hledani: %string%
function j3w_helper_match_rewrite_slug($slug)
{
    //preg_match("/%.*?%/", $slug, $matches);
    preg_match('/%(.*?)%/', $slug, $matches);
    return $matches;
}

function j3w_get_ancestors_path($term_id, $taxonomy)
{
    $ancestors_path = [];
    $ancestors = array_reverse(get_ancestors($term_id, $taxonomy));

    if ($ancestors) {
        foreach ($ancestors as $id) {
            $ancestors_path[] = get_term($id)->slug;
        }
    }

    $path = implode("/", $ancestors_path) . "/";

    return $path;
}

/**
 * Returns the primary term for the chosen taxonomy set by Yoast SEO
 * or the first term selected.
 *
 * @link https://www.tannerrecord.com/how-to-get-yoasts-primary-category/
 * @param integer $post The post id.
 * @param string  $taxonomy The taxonomy to query. Defaults to category.
 * @return array The term with keys of 'title', 'slug', and 'url'.
 */
function j3w_get_primary_taxonomy_term( $post = 0, $taxonomy = 'category' )
{
    if ( ! $post ) {
        $post = get_the_ID();
    }

    $terms        = get_the_terms( $post, $taxonomy );
    $primary_term = array();

    if ( $terms ) {
        $term_display = '';
        $term_slug    = '';
        $term_link    = '';
        if ( class_exists( 'WPSEO_Primary_Term' ) ) {
            $wpseo_primary_term = new WPSEO_Primary_Term( $taxonomy, $post );
            $wpseo_primary_term = $wpseo_primary_term->get_primary_term();
            $term               = get_term( $wpseo_primary_term );
            if ( is_wp_error( $term ) ) {
                $term_display = $terms[0]->name;
                $term_slug    = $terms[0]->slug;
                $term_link    = get_term_link( $terms[0]->term_id );
            } else {
                $term_display = $term->name;
                $term_slug    = $term->slug;
                $term_link    = get_term_link( $term->term_id );
            }
        } else {
            $term_display = $terms[0]->name;
            $term_slug    = $terms[0]->slug;
            $term_link    = get_term_link( $terms[0]->term_id );
        }
        $primary_term['url']   = $term_link;
        $primary_term['slug']  = $term_slug;
        $primary_term['title'] = $term_display;
    }
    return $primary_term;
}
