import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ToolbarGroup, ToolbarButton, Popover, TextControl, Button } from '@wordpress/components';
import {
	__unstableUseRichText as useRichText,
	toggleFormat,
	applyFormat,
	removeFormat,
	getActiveFormat,
	isCollapsed,
	insert,
} from '@wordpress/rich-text';
import { formatBold, formatItalic, link as linkIcon, linkOff } from '@wordpress/icons';
// El registro de negrita/cursiva/enlace (con sus mapeos a <strong>/<em>/<a>)
// viene de format-library; se usa solo por su efecto de registro, no por su
// UI (sus componentes "edit" dependen de @wordpress/block-editor, que
// requiere un <BlockEditorProvider> real para mostrar el toolbar -- ver
// SPEC 09). La barra de aquí se arma a mano sobre las funciones de bajo
// nivel de @wordpress/rich-text.
import '@wordpress/format-library';

const BOLD_FORMAT = 'core/bold';
const ITALIC_FORMAT = 'core/italic';
const LINK_FORMAT = 'core/link';

export default function RichTextBody( { value, onChange, placeholder } ) {
	const [ selection, setSelection ] = useState( {} );
	const [ isLinkPopoverOpen, setIsLinkPopoverOpen ] = useState( false );
	const [ linkUrl, setLinkUrl ] = useState( '' );

	// __unstableUseRichText está deprecado como API pública, pero sigue
	// siendo la única forma no privada de enlazar un <div contentEditable>
	// a un valor de @wordpress/rich-text sin depender de @wordpress/block-editor.
	const {
		value: richValue,
		onChange: onRichChange,
		ref,
	} = useRichText( {
		value,
		selectionStart: selection.start,
		selectionEnd: selection.end,
		onSelectionChange: ( start, end ) => setSelection( { start, end } ),
		onChange,
		placeholder,
	} );

	const isBoldActive = !! getActiveFormat( richValue, BOLD_FORMAT );
	const isItalicActive = !! getActiveFormat( richValue, ITALIC_FORMAT );
	const activeLink = getActiveFormat( richValue, LINK_FORMAT );

	const toggleInlineFormat = ( formatType ) => {
		onRichChange( toggleFormat( richValue, { type: formatType } ) );
	};

	const openLinkPopover = () => {
		setLinkUrl( activeLink ? activeLink.attributes.url : '' );
		setIsLinkPopoverOpen( true );
	};

	const applyLink = () => {
		if ( ! linkUrl ) {
			return;
		}

		let newValue;

		if ( isCollapsed( richValue ) ) {
			const start = richValue.start ?? richValue.text.length;
			newValue = insert( richValue, linkUrl );
			newValue = applyFormat(
				newValue,
				{ type: LINK_FORMAT, attributes: { url: linkUrl } },
				start,
				start + linkUrl.length
			);
		} else {
			newValue = applyFormat( richValue, {
				type: LINK_FORMAT,
				attributes: { url: linkUrl },
			} );
		}

		onRichChange( newValue );
		setIsLinkPopoverOpen( false );
	};

	const removeLink = () => {
		onRichChange( removeFormat( richValue, LINK_FORMAT ) );
		setIsLinkPopoverOpen( false );
	};

	return (
		<div className="booking-plugin-rich-text-body">
			<ToolbarGroup className="booking-plugin-rich-text-body__toolbar">
				<ToolbarButton
					icon={ formatBold }
					label={ __( 'Negrita', 'booking-plugin' ) }
					isPressed={ isBoldActive }
					onClick={ () => toggleInlineFormat( BOLD_FORMAT ) }
				/>
				<ToolbarButton
					icon={ formatItalic }
					label={ __( 'Cursiva', 'booking-plugin' ) }
					isPressed={ isItalicActive }
					onClick={ () => toggleInlineFormat( ITALIC_FORMAT ) }
				/>
				<ToolbarButton
					icon={ linkIcon }
					label={ __( 'Enlace', 'booking-plugin' ) }
					isPressed={ !! activeLink }
					onClick={ openLinkPopover }
				/>
			</ToolbarGroup>

			{ isLinkPopoverOpen && (
				<Popover
					position="bottom left"
					onClose={ () => setIsLinkPopoverOpen( false ) }
					className="booking-plugin-rich-text-body__link-popover"
				>
					<div className="booking-plugin-rich-text-body__link-form">
						<TextControl
							label={ __( 'URL del enlace', 'booking-plugin' ) }
							value={ linkUrl }
							onChange={ setLinkUrl }
							placeholder="https://"
						/>
						<div className="booking-plugin-rich-text-body__link-actions">
							<Button variant="primary" onClick={ applyLink }>
								{ __( 'Aplicar', 'booking-plugin' ) }
							</Button>
							{ activeLink && (
								<Button
									variant="tertiary"
									isDestructive
									icon={ linkOff }
									onClick={ removeLink }
								>
									{ __( 'Quitar enlace', 'booking-plugin' ) }
								</Button>
							) }
						</div>
					</div>
				</Popover>
			) }

			<div
				ref={ ref }
				contentEditable
				suppressContentEditableWarning
				className="booking-plugin-rich-text-body__editable"
			/>
		</div>
	);
}
