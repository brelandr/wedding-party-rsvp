/**
 * DataViews guest table: fetches wgrsvp/v1/guest-rows on each view change.
 */
import { DataViews } from '@wordpress/dataviews/wp';
import { TextControl, CheckboxControl } from '@wordpress/components';
import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const defaultLayouts = {
	table: {
		layout: {
			primaryField: 'guest_name',
		},
	},
};

const RSVP_STATUS_ELEMENTS = [
	{ label: __( 'Pending', 'wedding-party-rsvp' ), value: 'Pending' },
	{ label: __( 'Accepted', 'wedding-party-rsvp' ), value: 'Accepted' },
	{ label: __( 'Declined', 'wedding-party-rsvp' ), value: 'Declined' },
];

function getRsvpStatusFromFilters( filters ) {
	if ( ! Array.isArray( filters ) ) {
		return '';
	}
	const f = filters.find( ( x ) => x && x.field === 'rsvp_status' );
	if ( ! f || ! f.value ) {
		return '';
	}
	const v = String( f.value );
	if ( v === 'Pending' || v === 'Accepted' || v === 'Declined' ) {
		return v;
	}
	return '';
}

function getMenuChoiceFromFilters( filters ) {
	if ( ! Array.isArray( filters ) ) {
		return '';
	}
	const f = filters.find( ( x ) => x && x.field === 'menu_choice' );
	if ( ! f || f.value === undefined || f.value === null || f.value === '' ) {
		return '';
	}
	return String( f.value );
}

function getWprAttendedFromFilters( filters ) {
	if ( ! Array.isArray( filters ) ) {
		return '';
	}
	const f = filters.find( ( x ) => x && x.field === 'wpr_attended' );
	if ( ! f || f.value === undefined || f.value === null || f.value === '' ) {
		return '';
	}
	const v = String( f.value );
	if ( '1' === v || '0' === v ) {
		return v;
	}
	return '';
}

function buildRestPath( view, extra, proDv ) {
	const page = Math.max( 1, parseInt( view.page, 10 ) || 1 );
	const perPage = Math.max( 1, parseInt( view.perPage, 10 ) || 25 );
	const sortField =
		view.sort && view.sort.field ? String( view.sort.field ) : 'id';
	const sortDir =
		view.sort && view.sort.direction === 'desc' ? 'desc' : 'asc';
	const search = view.search ? String( view.search ).trim() : '';
	const rsvp = getRsvpStatusFromFilters( view.filters );
	const menu = getMenuChoiceFromFilters( view.filters );
	const attended = proDv ? getWprAttendedFromFilters( view.filters ) : '';

	const args = {
		page,
		per_page: Math.min( 100, perPage ),
		orderby: sortField,
		order: sortDir,
	};
	if ( search !== '' ) {
		args.search = search;
	}
	if ( rsvp !== '' ) {
		args.rsvp_status = rsvp;
	}
	if ( menu !== '' ) {
		args.menu_choice = menu;
	}
	const ex = extra || {};
	if ( ex.dietary && String( ex.dietary ).trim() !== '' ) {
		args.dietary_contains = String( ex.dietary ).trim();
	}
	if ( ex.allergy && String( ex.allergy ).trim() !== '' ) {
		args.allergy_contains = String( ex.allergy ).trim();
	}
	if ( ex.hasTable ) {
		args.has_table = '1';
	}
	if ( ex.table && String( ex.table ).trim() !== '' ) {
		args.table_equals = String( ex.table ).trim();
	}
	if ( proDv && attended !== '' ) {
		args.wpr_attended = attended;
	}
	if (
		proDv &&
		ex.plannerTag &&
		String( ex.plannerTag ).trim() !== ''
	) {
		args.wpr_planner_tag = String( ex.plannerTag ).trim();
	}
	return addQueryArgs( '/wgrsvp/v1/guest-rows', args );
}

export default function App() {
	const cfg =
		typeof window !== 'undefined' ? window.wgrsvpGuestDataviews : null;
	const proDv = cfg && cfg.proDataviews === true;
	const mealElements = useMemo( () => {
		const raw = cfg && cfg.mealElements ? cfg.mealElements : [];
		return Array.isArray( raw ) ? raw : [];
	}, [ cfg ] );
	const fields = useMemo( () => {
		const mealField = {
			id: 'menu_choice',
			label: __( 'Meal', 'wedding-party-rsvp' ),
			enableSorting: true,
		};
		if ( mealElements.length > 0 ) {
			mealField.elements = mealElements;
		}
		const proFields = proDv
			? [
					{
						id: 'wpr_attended',
						label: __( 'Check-in', 'wedding-party-rsvp' ),
						elements: [
							{
								label: __( 'Checked in', 'wedding-party-rsvp' ),
								value: '1',
							},
							{
								label: __(
									'Not checked in',
									'wedding-party-rsvp'
								),
								value: '0',
							},
						],
						enableSorting: false,
					},
					{
						id: 'wpr_pro_attended_at',
						label: __( 'Checked in at', 'wedding-party-rsvp' ),
						enableSorting: true,
					},
					{
						id: 'wpr_pro_planner_tags',
						label: __( 'Planner tags', 'wedding-party-rsvp' ),
						enableSorting: true,
					},
				]
			: [];
		return [
			{
				id: 'id',
				label: __( 'ID', 'wedding-party-rsvp' ),
				enableGlobalSearch: false,
				enableSorting: true,
			},
			{
				id: 'party_id',
				label: __( 'Party ID', 'wedding-party-rsvp' ),
				enableGlobalSearch: true,
				enableSorting: true,
			},
			{
				id: 'guest_name',
				label: __( 'Name', 'wedding-party-rsvp' ),
				enableGlobalSearch: true,
				enableSorting: true,
			},
			{
				id: 'email',
				label: __( 'Email', 'wedding-party-rsvp' ),
				enableGlobalSearch: true,
				enableSorting: true,
			},
			{
				id: 'phone',
				label: __( 'Phone', 'wedding-party-rsvp' ),
				enableSorting: false,
			},
			{
				id: 'rsvp_status',
				label: __( 'RSVP', 'wedding-party-rsvp' ),
				elements: RSVP_STATUS_ELEMENTS,
				enableSorting: true,
			},
			mealField,
			{
				id: 'child_menu_choice',
				label: __( 'Child meal', 'wedding-party-rsvp' ),
				enableSorting: false,
			},
			{
				id: 'dietary_restrictions',
				label: __( 'Dietary', 'wedding-party-rsvp' ),
				enableSorting: false,
			},
			{
				id: 'allergies',
				label: __( 'Allergies', 'wedding-party-rsvp' ),
				enableSorting: false,
			},
			{
				id: 'table_number',
				label: __( 'Tbl', 'wedding-party-rsvp' ),
				enableSorting: true,
			},
			...proFields,
		];
	}, [ mealElements, proDv ] );

	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 25,
		search: '',
		filters: [],
		sort: { field: 'id', direction: 'asc' },
		layout: defaultLayouts.table.layout,
		fields: fields.map( ( f ) => f.id ),
	} );

	const [ records, setRecords ] = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const debounceRef = useRef( null );
	const [ debouncedView, setDebouncedView ] = useState( view );
	const [ extraFilters, setExtraFilters ] = useState( {
		dietary: '',
		allergy: '',
		hasTable: false,
		table: '',
		plannerTag: '',
	} );
	const [ debouncedExtra, setDebouncedExtra ] = useState( extraFilters );

	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setDebouncedView( view );
			setDebouncedExtra( extraFilters );
		}, 320 );
		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ view, extraFilters ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		const path = buildRestPath( debouncedView, debouncedExtra, proDv );
		apiFetch( { path } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				if ( ! data || ! Array.isArray( data.guests ) ) {
					throw new Error( 'bad' );
				}
				setRecords( data.guests );
				setTotalItems( parseInt( data.total, 10 ) || 0 );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						cfg && cfg.i18n && cfg.i18n.error
							? cfg.i18n.error
							: __( 'Could not load guest data.', 'wedding-party-rsvp' )
					);
					setRecords( [] );
					setTotalItems( 0 );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ debouncedView, debouncedExtra, cfg, proDv ] );

	const perPage = Math.max( 1, parseInt( view.perPage, 10 ) || 25 );
	const paginationInfo = useMemo( () => {
		const totalPages = Math.max(
			1,
			Math.ceil( totalItems / perPage )
		);
		return {
			totalItems,
			totalPages,
		};
	}, [ totalItems, perPage ] );

	const listBase =
		cfg && cfg.listUrl
			? cfg.listUrl
			: '/wp-admin/admin.php?page=wedding-rsvp-main';

	const actions = useMemo(
		() => [
			{
				id: 'wgrsvp-open-in-list',
				label: __( 'Open in guest list', 'wedding-party-rsvp' ),
				supportsBulk: false,
				callback( items ) {
					const item = items && items[ 0 ];
					if ( ! item || ! item.id ) {
						return;
					}
					const url = `${ listBase }#wgrsvp-guest-row-${ encodeURIComponent(
						String( item.id )
					) }`;
					window.location.assign( url );
				},
			},
		],
		[ listBase ]
	);

	if ( error && ! loading ) {
		return (
			<div className="notice notice-error">
				<p>{ error }</p>
			</div>
		);
	}

	const ix = cfg && cfg.i18n ? cfg.i18n : {};

	return (
		<>
			<div
				className="wgrsvp-dataviews-extra-filters"
				style={ {
					marginBottom: '12px',
					padding: '12px',
					background: '#f6f7f7',
					border: '1px solid #c3c4c7',
				} }
			>
				<p className="description" style={ { marginTop: 0 } }>
					{ ix.filterApplyNote ||
						__(
							'These filters apply to the table below (combined with column filters).',
							'wedding-party-rsvp'
						) }
				</p>
				<TextControl
					label={
						ix.filterDietary ||
						__( 'Dietary contains', 'wedding-party-rsvp' )
					}
					value={ extraFilters.dietary }
					onChange={ ( v ) =>
						setExtraFilters( ( prev ) => ( {
							...prev,
							dietary: v,
						} ) )
					}
				/>
				<TextControl
					label={
						ix.filterAllergies ||
						__( 'Allergies contain', 'wedding-party-rsvp' )
					}
					value={ extraFilters.allergy }
					onChange={ ( v ) =>
						setExtraFilters( ( prev ) => ( {
							...prev,
							allergy: v,
						} ) )
					}
				/>
				<TextControl
					label={
						ix.filterTable ||
						__( 'Table number (exact)', 'wedding-party-rsvp' )
					}
					value={ extraFilters.table }
					onChange={ ( v ) =>
						setExtraFilters( ( prev ) => ( {
							...prev,
							table: v,
						} ) )
					}
				/>
				<CheckboxControl
					label={
						ix.filterHasTable ||
						__(
							'Only guests with a table number',
							'wedding-party-rsvp'
						)
					}
					checked={ extraFilters.hasTable }
					onChange={ ( checked ) =>
						setExtraFilters( ( prev ) => ( {
							...prev,
							hasTable: checked,
						} ) )
					}
				/>
				{ proDv && (
					<TextControl
						label={
							ix.filterPlannerTag ||
							__( 'Planner tag (slug)', 'wedding-party-rsvp' )
						}
						value={ extraFilters.plannerTag }
						onChange={ ( v ) =>
							setExtraFilters( ( prev ) => ( {
								...prev,
								plannerTag: v,
							} ) )
						}
					/>
				) }
				{ ! proDv && (
					<p className="description" style={ { marginBottom: 0 } }>
						{ ix.proFiltersNote ||
							__(
								'Check-in and planner tag filters, and the extra columns, require Wedding Party RSVP Pro with an active license.',
								'wedding-party-rsvp'
							) }
					</p>
				) }
			</div>
			{ loading && (
				<p className="description">
					{ __(
						'Loading guest rows…',
						'wedding-party-rsvp'
					) }
				</p>
			) }
			<DataViews
				data={ records }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				defaultLayouts={ defaultLayouts }
				actions={ actions }
				paginationInfo={ paginationInfo }
			/>
		</>
	);
}
