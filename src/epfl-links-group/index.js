import './style.scss'

const { __ } = wp.i18n;

const {
	registerBlockType,
} = wp.blocks;

const {
    TextControl,
} = wp.components;

const { Fragment } = wp.element;

const maxLinksGroup = 10;

const getAttributes = () => {
    let atts = {
		'title': {
            type: 'string',
        },
        'mainUrl': {
            type: 'boolean',
		},
	};

    for (var i = 1; i <= maxLinksGroup; i++) {
        atts['label'+i] = {
			type: 'string',
        };
        atts['url'+i] = {
			type: 'string',
        };
    }

    return atts;
}

function LinkGroupPanel ( props ) {
    const { attributes, setAttributes, index } = props;

    const setIndexedAttributes = (field_name, value) => {
        setAttributes({[`${field_name}${index}`]: value});
     };

    // set a value or an empty string for each Control, or face :
    // https://stackoverflow.com/questions/37427508/react-changing-an-uncontrolled-input

    return (
        <div>
            <TextControl
                label={ __('Label', 'wp-gutenberg-epfl') }
                value={ attributes['label' + index] || ''}
                onChange={ value => setIndexedAttributes('label', value) }
                help= { __('Link label', 'wp-gutenberg-epfl') }
            />
            <TextControl
                label={ __('URL', 'wp-gutenberg-epfl') }
                value={ attributes['url' + index]  || '' }
                onChange={ value => setIndexedAttributes('url', value) }
                help= { __('Link URL', 'wp-gutenberg-epfl') }
            />
            <hr />
        </div>
    );
}

registerBlockType( 'epfl/links-group', {
	title: __( 'EPFL Links group', 'wp-gutenberg-epfl'),
	description: 'v1.0.0',
	icon: 'editor-kitchensink',
	category: 'common',
	attributes: getAttributes(),
	supports : {
		customClassName: false, // Removes the default field in the inspector that allows you to assign a custom class
	},
	edit: ( props ) => {
        const { attributes, className, setAttributes } = props;

        return (
            <Fragment>
                <div className={ className }>
                    <h2>EPFL Links group</h2>
                    <TextControl
                        label={ __('Title', 'wp-gutenberg-epfl') }
                        value={ attributes.title }
                        onChange={ title => setAttributes( { title } ) }
                        help={ <a target="_blank" href="https://epfl-idevelop.github.io/elements/#/molecules/links-group">{ __('Documentation', 'wp-gutenberg-epfl') }</a> }
                    />
                    <TextControl
                        label={ __('URL', 'wp-gutenberg-epfl') }
                        value={ attributes.mainUrl }
                        onChange={ mainUrl => setAttributes( { mainUrl } ) }
                    />
                    <h4>Links</h4>
                    {[...Array(maxLinksGroup)].map((x, i) =>
                    <LinkGroupPanel key={i+1} { ...{ attributes, setAttributes, index:i+1 } }  />
                    )}
                </div>
            </Fragment>
		)
	},
	save: ( props ) => {
		return null;
	},
} );