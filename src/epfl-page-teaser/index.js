import InspectorControlsPageTeaser from './inspector'

const { __ } = wp.i18n
const { registerBlockType } = wp.blocks
const { Fragment } = wp.element

registerBlockType(
	'epfl/page-teaser',
	{
		title: __( "EPFL Page Teaser", 'wp-gutenberg-epfl'),
		description: 'v1.0.0',
		category: 'common',
		keywords: [
            __( 'page' , 'wp-gutenberg-epfl'),
            __( 'teaser' , 'wp-gutenberg-epfl'),
		],
		attributes: {
			page1: {
				type: 'string',
				default: null,
            },
            page2: {
				type: 'string',
				default: null,
            },
            page3: {
				type: 'string',
				default: null,
            },
            gray: {
                type: 'string',
            }
		},
		supports : {
			customClassName: false, // Removes the default field in the inspector that allows you to assign a custom class
		},

		edit: props => {
			const { attributes, className, setAttributes } = props
			return (
				<Fragment>
					<InspectorControlsPageTeaser { ...{ attributes, setAttributes } } />
					<div className={ className }>
                        <div id="preview-box">
                            <h2>EPFL PAGE TEASER</h2>
                            <div class="helper">{ __('Please fill the fields in the right-hand column', 'wp-gutenberg-epfl') }</div>
                        </div>
                    </div>
				</Fragment>
			)
		},

		save: props => {
            return null
        },
	}
)