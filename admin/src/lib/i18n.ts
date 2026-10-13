/**
 * Textos en español, preparados para traducción con wp.i18n (text domain imagina-turbo).
 */
export function __( text: string ): string {
	const translate = window.wp?.i18n?.__;
	return translate ? translate( text, 'imagina-turbo' ) : text;
}
