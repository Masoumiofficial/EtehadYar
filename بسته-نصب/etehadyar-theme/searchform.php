<?php
/**
 * Search form.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>"
	style="display:flex;gap:8px;margin-top:14px">
	<label class="screen-reader-text" for="ey-s"><?php esc_html_e( 'جست‌وجو', 'etehadyar-theme' ); ?></label>
	<input id="ey-s" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
		placeholder="<?php esc_attr_e( 'جست‌وجو…', 'etehadyar-theme' ); ?>"
		style="flex:1;padding:10px 13px;border:1px solid var(--ey-line);border-radius:10px;font:inherit">
	<button class="ey-btn" type="submit"><?php esc_html_e( 'بگرد', 'etehadyar-theme' ); ?></button>
</form>
