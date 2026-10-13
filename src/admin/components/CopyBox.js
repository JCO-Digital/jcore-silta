import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A read-only code box with a Copy button.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Text to show and copy.
 * @param {string} props.label Accessible label of the box.
 * @return {Element} Box.
 */
export default function CopyBox( { value, label } ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useRef();

	useEffect( () => {
		if ( ! copied ) {
			return;
		}
		const timer = setTimeout( () => setCopied( false ), 3000 );
		return () => clearTimeout( timer );
	}, [ copied ] );

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( value );
			setCopied( true );
		} catch {
			// Clipboard access can be denied (e.g. plain HTTP); select the text instead.
			ref.current?.select();
		}
	};

	return (
		<div className="jcore-silta-copy">
			<textarea
				ref={ ref }
				className="jcore-silta-copy__code code"
				aria-label={ label }
				value={ value }
				readOnly
				rows={ Math.min( 6, Math.ceil( value.length / 70 ) + 1 ) }
				onFocus={ ( event ) => event.target.select() }
			/>
			<Button __next40pxDefaultSize variant="secondary" onClick={ copy }>
				{ copied
					? __( 'Copied', 'jcore-silta' )
					: __( 'Copy', 'jcore-silta' ) }
			</Button>
		</div>
	);
}
