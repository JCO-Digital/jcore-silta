import { Button, Flex } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Previous / next page buttons.
 *
 * @param {Object}                       props          Props.
 * @param {number}                       props.page     Current page, from 1.
 * @param {number}                       props.pages    Number of pages.
 * @param {(...args: unknown[]) => void} props.onChange Called with the new page.
 * @return {Element|null} Pagination.
 */
export default function Pagination( { page, pages, onChange } ) {
	if ( pages <= 1 ) {
		return null;
	}
	return (
		<Flex className="jcore-silta-pagination" justify="flex-end" gap={ 2 }>
			<Button
				size="compact"
				variant="secondary"
				disabled={ page <= 1 }
				accessibleWhenDisabled
				onClick={ () => onChange( page - 1 ) }
			>
				{ __( 'Previous', 'jcore-silta' ) }
			</Button>
			<span>
				{ sprintf(
					/* translators: 1: current page, 2: number of pages. */
					__( 'Page %1$d of %2$d', 'jcore-silta' ),
					page,
					pages
				) }
			</span>
			<Button
				size="compact"
				variant="secondary"
				disabled={ page >= pages }
				accessibleWhenDisabled
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'jcore-silta' ) }
			</Button>
		</Flex>
	);
}
