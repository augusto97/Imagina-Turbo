<?php
/**
 * Dobles de Proxy Cache Purge y Breeze (sus clases públicas, sin namespace), que guardan las llamadas.
 *
 * @package ImaginaTurbo
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, PEAR.NamingConventions.ValidClassName.Invalid, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- dobles de clases de otros plugins.

/**
 * Proxy Cache Purge 5.2.0: VarnishPurger::purge_url( $url ) es pública y estática.
 */
class VarnishPurger {

	/** @var list<string> */
	public static array $calls = [];

	public static function purge_url( $url ) {
		self::$calls[] = $url;
	}
}

/**
 * Breeze: Breeze_PurgeVarnish::purge_cache( $url ).
 */
class Breeze_PurgeVarnish {

	/** @var list<string> */
	public static array $calls = [];

	public function purge_cache( $url ) {
		self::$calls[] = $url;
		return [ 'response' => [ 'code' => 200 ] ];
	}
}
