<?php
/**
 * Tipos de request que distingue el clasificador.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Request;

/**
 * Tipo de request, decidido sin WordPress cargado.
 */
enum RequestType: string {

	case FrontendHtml = 'FrontendHtml';
	case WcAjax       = 'WcAjax';
	case AdminAjax    = 'AdminAjax';
	case Rest         = 'Rest';
	case StoreApi     = 'StoreApi';
	case Cron         = 'Cron';
	case Cli          = 'Cli';
	case Admin        = 'Admin';
	case Login        = 'Login';
	case XmlRpc       = 'XmlRpc';
	case Feed         = 'Feed';
	case Sitemap      = 'Sitemap';
	case Robots       = 'Robots';
	case StaticMiss   = 'StaticMiss';
	case Checkout     = 'Checkout';
	case Cart         = 'Cart';
	case Account      = 'Account';
	/** Frontend con método distinto de GET/HEAD o scripts del core (comentarios, registro). Nunca se cachea ni se filtra. */
	case Other = 'Other';

	/**
	 * Requests en los que nunca se filtran plugins (regla 7 de PluginFilter).
	 */
	public function isNeverFiltered(): bool {
		return match ( $this ) {
			self::Admin, self::Login, self::Cron, self::Cli, self::Checkout, self::Account, self::Other => true,
			default => false,
		};
	}

	/**
	 * Requests que pueden producir HTML cacheable para visitantes anónimos.
	 */
	public function isPotentiallyCacheable(): bool {
		return match ( $this ) {
			self::FrontendHtml, self::Feed, self::Sitemap, self::Robots => true,
			default => false,
		};
	}
}
