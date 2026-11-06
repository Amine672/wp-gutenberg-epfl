import * as axios from 'axios';
import {
    hasCommonCategory,
    getTooltippedAttributes,
    getTooltippedExample,
} from '../block-utils.js'

import studentProjectsIcon from './student-projects-icon'
import InspectorControlsStudentProjects from './inspector'
import ServerSideRender from '@wordpress/server-side-render'

export const version = "v1.1.0";

const { __ } = wp.i18n;

const {
    registerBlockType,
} = wp.blocks;

const {
    InspectorControls,
} = wp.editor;

const {
    TextControl,
} = wp.components;

const { Fragment } = wp.element;

registerBlockType( 'epfl/student-projects', {
    title: __( 'EPFL Student Projects', 'epfl'),
    description: __(
        'Display a list of semester or master\'s projects from IS Academia',
        'epfl'
    ),
    icon: studentProjectsIcon,
    category: hasCommonCategory ? 'common' : 'embed',
    attributes: getTooltippedAttributes({
        title:{
            type: 'string',
        },
        section:{
            type: 'string',
        },
        onlyCurrentProjects: {
            type: 'boolean',
        },
        onlyArchivedProjects: {
            type: 'boolean',
        },
        professorScipers: {
            type: 'string',
        },
        apiSource:{
            type: 'string',
        },
        zenFetchMode:{
            type: 'string',
        },
        zenSchool:{
            type: 'string',
        },
        zenTags:{
            type: 'string',
        },
        zenMatchAllTags:{
            type: 'boolean',
        }
    }),
    example: getTooltippedExample(),
    supports : {
        customClassName: false, // Removes the default field in the inspector that allows you to assign a custom class
    },
    edit: ( props ) => {
        const { attributes, className, setAttributes } = props

        if ( attributes.asToolTip ) {
            // render the tooltip
            return(
                <Fragment>
                    <img src={ blockThumbnails.studentProjects } />
                </Fragment>
            );
        }

        // Validation: Check if form is properly filled
        let validationError = null;
        if (!attributes.apiSource || attributes.apiSource === '') {
            validationError = __('Please select an API source.', 'epfl');
        } else if (attributes.apiSource === 'zen') {
            if (!attributes.zenFetchMode || attributes.zenFetchMode === '') {
                validationError = __('Please select a fetch mode (By Unit, By Professor SCIPER, By Tag(s) or By School).', 'epfl');
            } else if (attributes.zenFetchMode === 'section' && (!attributes.zenSchool || attributes.zenSchool === '')) {
                validationError = __('Please select a school.', 'epfl');
            } else if (attributes.zenFetchMode === 'section' && (!attributes.section || attributes.section === '')) {
                validationError = __('Please select a unit.', 'epfl');
            } else if (attributes.zenFetchMode === 'sciper' && (!attributes.professorScipers || attributes.professorScipers.trim() === '')) {
                validationError = __('Please enter at least one professor SCIPER.', 'epfl');
            } else if (attributes.zenFetchMode === 'sciper' && !/^\d{6}(,\d{6})*$/.test(attributes.professorScipers.replace(/\s/g, '').replace(/^,+|,+$/g, ''))) {
                validationError = __('Invalid professor SCIPER(s). A SCIPER is a 6-digit number; separate them with commas.', 'epfl');
            } else if (attributes.zenFetchMode === 'school' && (!attributes.zenSchool || attributes.zenSchool === '')) {
                validationError = __('Please select a school.', 'epfl');
            } else if (attributes.zenFetchMode === 'tag' && (!attributes.zenTags || attributes.zenTags.trim() === '')) {
                validationError = __('Please select at least one tag.', 'epfl');
            }
        } else if (attributes.apiSource === 'isa') {
            if (!attributes.section || attributes.section === '') {
                validationError = __('Please select a section.', 'epfl');
            }
        }

        return (
            <Fragment>
                <InspectorControlsStudentProjects { ...{ attributes, setAttributes } } />
                <div className={ className }>
                    <h2 className="epfl-block-title">{ __('EPFL Student Projects', 'epfl') }</h2>
                    <TextControl
							label={ __('Title', 'epfl') }
							value={ attributes.title }
							onChange={ title => setAttributes( { title } ) }
						/>
                    {validationError && (
                        <div style={{ 
                            padding: '12px', 
                            marginTop: '12px', 
                            backgroundColor: '#fff3cd', 
                            border: '1px solid #ffc107', 
                            borderRadius: '4px',
                            color: '#856404'
                        }}>
                            <strong>{ __('Warning:', 'epfl') }</strong> {validationError}
                        </div>
                    )}
                    {!validationError && attributes.apiSource === 'zen' && (
                        // Live preview: the block rendered by the server, as on the published page
                        <div className="epfl-sp-editor-preview" style={{ marginTop: '16px' }}>
                            <ServerSideRender
                                block="epfl/student-projects"
                                attributes={ { ...attributes, asToolTip: false } }
                            />
                        </div>
                    )}
                </div>
            </Fragment>
        )
    },
    save: ( props ) => {
        return null
    },
} );
