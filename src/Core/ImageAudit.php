<?php
/**
 * Auditoría de imágenes (0.19.0): la biblioteca de medios, la cobertura de WebP/AVIF y los ajustes
 * del optimizador. Solo lee y recomienda: Imagina Turbo no cambia los ajustes de otro plugin.
 *
 * - Biblioteca: por lotes (el admin repite hasta terminar), con los metadatos de cada adjunto y la
 *   existencia de sus copias WebP y AVIF en las dos formas de nombrar (foto.jpg.webp y foto.webp).
 *   Originales de más de 2.560 px, archivos de más de 1 MB, adjuntos sin archivo o sin metadatos.
 * - EWWW Image Optimizer (verificado en el código de la 8.9.0): ewww_image_optimizer_jpg_level
 *   (10 Pixel Perfect, sin pérdida; 20 Pixel Perfect Plus y 30 Premium, con la API), _webp,
 *   _webp_quality (vacío = 75; EWWW la limita a 92 con el filtro webp_quality), _sharpen («Sharpen
 *   Images»: sharp YUV al crear el WebP), _webp_naming_mode (append | replace), _maxmediawidth y
 *   _maxmediaheight, _metadata_remove, _backup_files, _bulk_resume (vacío, «scanning» o la marca de
 *   una optimización masiva a medias) y las tablas {prefijo}ewwwio_queue y ewwwio_images (pending).
 *   La conversión local de JPG a WebP es siempre con pérdida (cwebp -q o Imagick con la calidad);
 *   la de PNG, sin pérdida.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Escaneo, estado del optimizador y recomendaciones.
 *
 * @phpstan-type Item array{id: int, file: string, width: int, height: int, bytes: int}
 * @phpstan-type Scan array{started_at: int, finished_at: int, cursor: int, done: bool, total: int, mime: array<string, int>, bytes_main: int, bytes_sizes: int, convertible: int, webp: int, avif: int, missing: int, no_meta: int, large_n: int, heavy_n: int, large: list<Item>, heavy: list<Item>}
 * @phpstan-type Ewww array{version: string, jpg_level: int, png_level: int, webp: bool, webp_quality: int, webp_effective: int, sharpen: bool, naming: string, max_width: int, max_height: int, metadata_remove: bool, backup: bool, cloud: bool, queue: int|null, pending: int|null, bulk_resume: string, optimized: int|null, saved_bytes: int|null}
 */
final class ImageAudit {

	public const OPTION = 'itb_image_audit';

	/** Lado máximo razonable de un original (el umbral de imágenes grandes del core). */
	public const LARGE_PX = 2560;

	/** Archivo pesado. */
	public const HEAVY_BYTES = 1048576;

	/** Adjuntos por consulta. */
	private const BATCH = 200;

	/** Elementos de cada lista. */
	private const TOP = 15;

	/** Optimizadores conocidos (carpeta del plugin => nombre). */
	public const OPTIMIZERS = [
		'ewww-image-optimizer'       => 'EWWW Image Optimizer',
		'shortpixel-image-optimiser' => 'ShortPixel',
		'imagify'                    => 'Imagify',
		'wp-smushit'                 => 'Smush',
		'webp-converter-for-media'   => 'Converter for Media',
		'optimole-wp'                => 'Optimole',
		'tiny-compress-images'       => 'TinyPNG',
	];

	/**
	 * Escaneo vacío.
	 *
	 * @return Scan
	 */
	public static function empty(): array {
		return [
			'started_at'  => time(),
			'finished_at' => 0,
			'cursor'      => 0,
			'done'        => false,
			'total'       => 0,
			'mime'        => [],
			'bytes_main'  => 0,
			'bytes_sizes' => 0,
			'convertible' => 0,
			'webp'        => 0,
			'avif'        => 0,
			'missing'     => 0,
			'no_meta'     => 0,
			'large_n'     => 0,
			'heavy_n'     => 0,
			'large'       => [],
			'heavy'       => [],
		];
	}

	/**
	 * Último escaneo guardado (null si nunca se hizo).
	 *
	 * @return Scan|null
	 */
	public static function stored(): ?array {
		$data = get_option( self::OPTION, null );
		if ( ! is_array( $data ) || ! isset( $data['cursor'], $data['total'] ) ) {
			return null;
		}
		/** @var Scan */
		return array_merge( self::empty(), $data );
	}

	/**
	 * Avanza el escaneo hasta terminar o agotar el tiempo.
	 *
	 * @return Scan
	 */
	public static function step( bool $restart, float $budget = 8.0 ): array {
		global $wpdb;
		$scan = $restart ? null : self::stored();
		if ( null === $scan || $scan['done'] ) {
			$scan = self::empty();
		}
		$uploads  = wp_get_upload_dir();
		$base     = trailingslashit( (string) $uploads['basedir'] );
		$deadline = microtime( true ) + $budget;
		while ( microtime( true ) < $deadline ) {
			$sql  = $wpdb->prepare(
				"SELECT p.ID, p.post_mime_type, f.meta_value AS file, m.meta_value AS meta FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file' LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				'image/%',
				$scan['cursor'],
				self::BATCH
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- preparado arriba; lectura por lotes en el admin.
			if ( ! is_array( $rows ) || [] === $rows ) {
				$scan['done']        = true;
				$scan['finished_at'] = time();
				break;
			}
			foreach ( $rows as $row ) {
				$meta = maybe_unserialize( (string) ( $row['meta'] ?? '' ) );
				$scan = self::add( $scan, (int) $row['ID'], (string) $row['post_mime_type'], (string) ( $row['file'] ?? '' ), is_array( $meta ) ? $meta : null, $base );
			}
		}
		update_option( self::OPTION, $scan, false );
		return $scan;
	}

	/**
	 * Suma un adjunto al escaneo.
	 *
	 * @param Scan                      $scan Escaneo.
	 * @param array<string, mixed>|null $meta _wp_attachment_metadata.
	 * @return Scan
	 */
	public static function add( array $scan, int $id, string $mime, string $file, ?array $meta, string $base ): array {
		$scan['cursor'] = max( $scan['cursor'], $id );
		++$scan['total'];
		$scan['mime'][ $mime ] = ( $scan['mime'][ $mime ] ?? 0 ) + 1;
		if ( '' === $file ) {
			++$scan['missing'];
			return $scan;
		}
		$path = str_starts_with( $file, '/' ) ? $file : $base . $file;
		if ( ! is_file( $path ) ) {
			++$scan['missing'];
			return $scan;
		}
		if ( null === $meta ) {
			++$scan['no_meta'];
		}
		$bytes               = (int) ( $meta['filesize'] ?? 0 ) ?: (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- archivo comprobado arriba.
		$scan['bytes_main'] += $bytes;
		$width               = (int) ( $meta['width'] ?? 0 );
		$height              = (int) ( $meta['height'] ?? 0 );
		$item                = [
			'id'     => $id,
			'file'   => substr( $file, 0, 200 ),
			'width'  => $width,
			'height' => $height,
			'bytes'  => $bytes,
		];
		if ( max( $width, $height ) > self::LARGE_PX ) {
			++$scan['large_n'];
			$scan['large'] = self::top( $scan['large'], $item );
		}
		if ( $bytes > self::HEAVY_BYTES ) {
			++$scan['heavy_n'];
			$scan['heavy'] = self::top( $scan['heavy'], $item );
		}
		$convertible = in_array( $mime, [ 'image/jpeg', 'image/png' ], true );
		$dir         = trailingslashit( dirname( $path ) );
		$files       = [ $path ];
		foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : [] as $size ) {
			if ( is_array( $size ) && is_string( $size['file'] ?? null ) && '' !== $size['file'] ) {
				$scan['bytes_sizes'] += (int) ( $size['filesize'] ?? 0 );
				$files[]              = $dir . basename( $size['file'] );
			}
		}
		if ( $convertible ) {
			foreach ( array_unique( $files ) as $one ) {
				++$scan['convertible'];
				$stem          = preg_replace( '/\.[^.\/]+$/', '', $one );
				$scan['webp'] += is_file( $one . '.webp' ) || is_file( $stem . '.webp' ) ? 1 : 0;
				$scan['avif'] += is_file( $one . '.avif' ) || is_file( $stem . '.avif' ) ? 1 : 0;
			}
		}
		return $scan;
	}

	/**
	 * Los más pesados primero.
	 *
	 * @param list<Item> $list Lista.
	 * @param Item       $item Nuevo.
	 * @return list<Item>
	 */
	private static function top( array $list, array $item ): array {
		$list[] = $item;
		usort( $list, static fn( array $a, array $b ): int => $b['bytes'] <=> $a['bytes'] );
		return array_slice( $list, 0, self::TOP );
	}

	/**
	 * Ajustes y cola de EWWW (null si no está activo).
	 *
	 * @return Ewww|null
	 */
	public static function ewww(): ?array {
		if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ) {
			return null;
		}
		global $wpdb;
		$get     = static fn( string $name ): mixed => function_exists( 'ewww_image_optimizer_get_option' ) ? \ewww_image_optimizer_get_option( 'ewww_image_optimizer_' . $name ) : get_option( 'ewww_image_optimizer_' . $name );
		$quality = (int) $get( 'webp_quality' );
		$quality = $quality >= 1 && $quality <= 100 ? $quality : 0;
		$exists  = static fn( string $table ): bool => $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tabla de otro plugin, solo lectura en el admin.
		$queue   = $wpdb->prefix . 'ewwwio_queue';
		$images  = $wpdb->prefix . 'ewwwio_images';
		$count   = static function ( string $sql ) use ( $wpdb ): ?int {
			$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- consultas fijas sobre tablas de EWWW.
			return is_numeric( $value ) ? (int) $value : null;
		};
		$hasQ    = $exists( $queue );
		$hasI    = $exists( $images );
		return [
			// La constante es un entero (890 en la 8.9.0): la versión legible está en la cabecera.
			'version'         => defined( 'EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE' ) ? (string) ( get_file_data( (string) constant( 'EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE' ), [ 'v' => 'Version' ] )['v'] ?? '' ) : (string) constant( 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
			'jpg_level'       => (int) $get( 'jpg_level' ),
			'png_level'       => (int) $get( 'png_level' ),
			'webp'            => (bool) $get( 'webp' ),
			'webp_quality'    => $quality,
			'webp_effective'  => min( 92, $quality > 0 ? $quality : 75 ),
			'sharpen'         => (bool) $get( 'sharpen' ),
			'naming'          => 'replace' === $get( 'webp_naming_mode' ) ? 'replace' : 'append',
			'max_width'       => (int) $get( 'maxmediawidth' ),
			'max_height'      => (int) $get( 'maxmediaheight' ),
			'metadata_remove' => (bool) $get( 'metadata_remove' ),
			'backup'          => (bool) $get( 'backup_files' ),
			'cloud'           => '' !== (string) $get( 'cloud_key' ),
			'queue'           => $hasQ ? $count( "SELECT COUNT(*) FROM {$queue}" ) : null,
			'pending'         => $hasI ? $count( "SELECT COUNT(*) FROM {$images} WHERE pending = 1" ) : null,
			'bulk_resume'     => substr( (string) get_option( 'ewww_image_optimizer_bulk_resume', '' ), 0, 40 ),
			'optimized'       => $hasI ? $count( "SELECT COUNT(*) FROM {$images} WHERE level > 0" ) : null,
			'saved_bytes'     => $hasI ? $count( "SELECT COALESCE(SUM(orig_size - image_size), 0) FROM {$images} WHERE orig_size > image_size" ) : null,
		];
	}

	/**
	 * Optimizadores de imágenes activos.
	 *
	 * @param list<string> $active Plugins activos (carpeta/archivo.php).
	 * @return list<string>
	 */
	public static function optimizers( array $active ): array {
		$out = [];
		foreach ( $active as $file ) {
			$slug = dirname( $file );
			if ( isset( self::OPTIMIZERS[ $slug ] ) ) {
				$out[] = self::OPTIMIZERS[ $slug ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Recomendaciones (texto en español), con nivel warn o info.
	 *
	 * @param Ewww|null    $ewww       Estado de EWWW.
	 * @param Scan|null    $scan       Último escaneo.
	 * @param list<string> $optimizers Optimizadores activos.
	 * @return list<array{id: string, level: string, text: string}>
	 */
	public static function recommendations( ?array $ewww, ?array $scan, array $optimizers ): array {
		$out = [];
		$add = static function ( string $id, string $level, string $text ) use ( &$out ): void {
			$out[] = [
				'id'    => $id,
				'level' => $level,
				'text'  => $text,
			];
		};
		if ( [] === $optimizers ) {
			$add( 'no_optimizer', 'warn', __( 'No hay un optimizador de imágenes activo: las fotos se sirven tal como se suben y sin WebP. EWWW Image Optimizer (gratis, compresión en el servidor) cubre lo necesario.', 'imagina-turbo' ) );
		}
		if ( count( $optimizers ) > 1 ) {
			/* translators: %s: lista de plugins. */
			$add( 'two_optimizers', 'warn', sprintf( __( 'Hay más de un optimizador activo (%s): procesan las mismas imágenes dos veces y pueden pisarse los WebP. Deja uno.', 'imagina-turbo' ), implode( ', ', $optimizers ) ) );
		}
		if ( null !== $ewww ) {
			if ( ! $ewww['webp'] ) {
				$add( 'ewww_webp', 'warn', __( 'EWWW no genera WebP: activa «WebP Conversion» en sus ajustes. Pesan entre 25 y 35 % menos que el JPG con la misma calidad visual.', 'imagina-turbo' ) );
			} elseif ( $ewww['webp_effective'] < 90 ) {
				/* translators: %d: calidad actual. */
				$add( 'ewww_webp_quality', 'warn', sprintf( __( 'La calidad WebP de EWWW es %d: con fotos de producto se nota. Súbela a 90–92 (WebP Quality; EWWW no pasa de 92) y activa «Sharpen Images» para que los colores y los bordes no se ablanden.', 'imagina-turbo' ), $ewww['webp_effective'] ) );
			} elseif ( ! $ewww['sharpen'] ) {
				$add( 'ewww_sharpen', 'info', __( 'Activa «Sharpen Images» en EWWW: crea los WebP con sharp YUV, que conserva mejor los colores saturados y los bordes finos.', 'imagina-turbo' ) );
			}
			if ( $ewww['webp'] ) {
				$add( 'ewww_webp_lossy', 'info', __( 'EWWW convierte los JPG a WebP siempre con pérdida (la calidad de arriba) y los PNG sin pérdida. Con 90–92 y Sharpen Images la diferencia no se ve; si una tienda no acepta ninguna pérdida, el WebP no sirve para sus JPG.', 'imagina-turbo' ) );
			}
			if ( 0 === $ewww['jpg_level'] ) {
				$add( 'ewww_jpg', 'warn', __( 'EWWW no comprime los JPG. «Pixel Perfect» es sin pérdida y gratis: quita lo que sobra del archivo sin tocar un píxel.', 'imagina-turbo' ) );
			} elseif ( $ewww['jpg_level'] >= 30 ) {
				$add( 'ewww_jpg_lossy', 'info', __( 'Los JPG se comprimen con el nivel Premium de EWWW (con pérdida, con su API). Si la tienda pide cero pérdida, usa Pixel Perfect.', 'imagina-turbo' ) );
			}
			if ( ! $ewww['metadata_remove'] ) {
				$add( 'ewww_metadata', 'info', __( 'Activa «Remove Metadata» en EWWW: quita EXIF y datos de cámara (sin pérdida de imagen) y ahorra unos KB por foto.', 'imagina-turbo' ) );
			}
			if ( 0 === $ewww['max_width'] && 0 === $ewww['max_height'] && null !== $scan && $scan['large_n'] > 0 ) {
				/* translators: %d: cantidad de imágenes. */
				$add( 'ewww_resize', 'warn', sprintf( __( '%d originales miden más de 2.560 px y EWWW no redimensiona. Pon 2560 en Max Width y Max Height (Resize Images) y usa «Resize Existing Images» en la optimización masiva.', 'imagina-turbo' ), $scan['large_n'] ) );
			}
			$pending = max( (int) $ewww['queue'], (int) $ewww['pending'] );
			if ( $pending > 0 || '' !== $ewww['bulk_resume'] ) {
				/* translators: %d: imágenes en cola. */
				$add( 'ewww_queue', 'warn', sprintf( __( 'La optimización masiva de EWWW quedó a medias (%d en cola). Reanúdala en Medios → Bulk Optimize; si la cola no avanza, revisa que WP-Cron o Action Scheduler corran.', 'imagina-turbo' ), $pending ) );
			}
		}
		if ( null !== $scan && $scan['done'] && $scan['convertible'] > 0 ) {
			$share = $scan['webp'] / $scan['convertible'];
			if ( ( null === $ewww || $ewww['webp'] ) && $share < 0.9 && [] !== $optimizers ) {
				/* translators: 1: porcentaje con WebP, 2: archivos sin WebP. */
				$add( 'webp_coverage', 'warn', sprintf( __( 'Solo el %1$d %% de los JPG y PNG (con sus tamaños) tiene WebP: faltan %2$d. Una optimización masiva los genera.', 'imagina-turbo' ), (int) round( $share * 100 ), $scan['convertible'] - $scan['webp'] ) );
			}
		}
		if ( null !== $scan && $scan['heavy_n'] > 0 ) {
			/* translators: %d: cantidad de imágenes. */
			$add( 'heavy', 'info', sprintf( __( '%d imágenes pesan más de 1 MB como se sirven. Revisa las primeras de la lista: casi siempre son fotos subidas sin redimensionar o PNG que deberían ser JPG.', 'imagina-turbo' ), $scan['heavy_n'] ) );
		}
		if ( null !== $scan && $scan['missing'] > 0 ) {
			/* translators: %d: cantidad de adjuntos. */
			$add( 'missing', 'info', sprintf( __( '%d adjuntos no tienen su archivo en el servidor (borrados a mano o de una migración incompleta).', 'imagina-turbo' ), $scan['missing'] ) );
		}
		return $out;
	}
}
