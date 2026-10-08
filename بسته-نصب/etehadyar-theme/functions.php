<?php
/**
 * Etehadyar theme bootstrap.
 *
 * The theme is deliberately thin: every piece of business logic — auth,
 * wallet, billing, quotas — lives in the etehadyar-core plugin. If the theme
 * is switched off the customer's money and data are untouched. This file
 * wires up presentation and nothing else.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

define( 'ETEHADYAR_THEME_VERSION', '1.1.0' );

require_once get_template_directory() . '/inc/class-theme-setup.php';
require_once get_template_directory() . '/inc/class-core-dependency.php';
require_once get_template_directory() . '/inc/class-theme-pages.php';
require_once get_template_directory() . '/inc/class-template-helpers.php';

Etehadyar_Theme_Setup::boot();
Etehadyar_Core_Dependency::boot();
Etehadyar_Theme_Pages::boot();
