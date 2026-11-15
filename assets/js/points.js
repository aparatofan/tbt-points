/**
 * TBT Points — student page [tbt_my_points] and teacher page [tbt_points].
 *
 * Teacher views live in the URL, so Back and a refresh keep them:
 *
 *   (none)                 Students tab
 *   tbtp_student={id}      one student's history ("← All students")
 *   tbtp_tab=rules         Rules tab (administrators)
 *   tbtp_q={text}          the Students search; kept while a student's
 *                          history is open, so "← All students" returns to it
 *
 * All data comes from tbt-points/v1; every visibility decision is made on
 * the server. Plain JS, no build step, no dependencies.
 */
( function () {
	'use strict';

	var CFG = window.TBTP_POINTS || {};
	var root = document.querySelector( '[data-tbtp-app]' );
	if ( ! root || ! CFG.rest ) {
		return;
	}

	var MODE = CFG.mode === 'teacher' ? 'teacher' : 'student';
	var IS_ADMIN = !! CFG.isAdmin;
	var P = { tab: 'tbtp_tab', student: 'tbtp_student', q: 'tbtp_q' };
	var TOOL_ORDER = [ 'test', 'homework', 'swipe', 'matching', 'dragdrop', 'drill', 'fill', 'lesson' ];
	var TOOL_NOTES = {
		homework: 'When you save a score',
		fill: 'Game points stay in Fill; finishing earns these',
		swipe: 'Done, no score',
		matching: 'Done, no score',
		drill: 'Done, no score',
		lesson: 'Added to Learning History; once per lesson',
		test: 'Gets the score bonus too',
		dragdrop: 'Gets the score bonus too',
	};

	/* ---------- Helpers ---------- */

	function esc( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function num( n ) {
		return Number( n || 0 ).toLocaleString( 'en-GB' );
	}

	function signed( n ) {
		n = Number( n || 0 );
		return ( n > 0 ? '+' : n < 0 ? '−' : '' ) + num( Math.abs( n ) );
	}

	function $( sel ) {
		return root.querySelector( sel );
	}

	/* ---------- REST ---------- */

	var noncePromise = null;

	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( CFG.ajax + ( CFG.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbtp_nonce', {
				credentials: 'same-origin',
				cache: 'no-store',
			} ).then( function ( res ) {
				return res.json();
			} ).then( function ( data ) {
				if ( ! data || ! data.success ) {
					throw new Error( 'nonce' );
				}
				CFG.nonce = data.data.nonce;
			} ).finally( function () {
				noncePromise = null;
			} );
		}
		return noncePromise;
	}

	/**
	 * Call a route. GET with params, or POST with a JSON body. Rejects with
	 * { code, message, status, errors }. An expired nonce is refreshed and the
	 * call retried once.
	 */
	function api( path, params, body, retried ) {
		var q = new URLSearchParams();
		Object.keys( params || {} ).forEach( function ( k ) {
			if ( params[ k ] !== '' && params[ k ] != null ) {
				q.set( k, params[ k ] );
			}
		} );
		var s = q.toString();
		var url = CFG.rest + path + ( s ? ( CFG.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + s : '' );
		var opts = {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': CFG.nonce || '' },
		};
		if ( body ) {
			opts.method = 'POST';
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					return refreshNonce().then( function () {
						return api( path, params, body, true );
					} );
				}
				throw {
					code: ( data && data.code ) || 'http_' + res.status,
					message: ( data && data.message ) || '',
					status: res.status,
					errors: ( data && data.data && data.data.errors ) || null,
				};
			} );
		}, function () {
			throw { code: 'network', message: '', status: 0, errors: null };
		} );
	}

	function errorText( err ) {
		if ( err && err.status === 401 ) {
			return 'You have been logged out. Reload the page and log in again.';
		}
		if ( err && err.message ) {
			return err.message;
		}
		return 'Could not load points. Check your connection and try again.';
	}

	/* ---------- Markup pieces ---------- */

	function adminBar( title, extra ) {
		return '<div class="tbtp-abar"><h2 class="tbtp-abar__title">' + esc( title ) + '</h2>' +
			'<span class="tbtp-abar__line" aria-hidden="true"></span>' + ( extra || '' ) + '</div>';
	}

	var SEARCH_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';

	/** The search box, as TBT Results draws it. */
	function searchBox( placeholder, value ) {
		return '<div class="tbtp-search">' + SEARCH_ICON +
			'<label class="tbtp-sr" for="tbtp-q">' + esc( placeholder ) + '</label>' +
			'<input id="tbtp-q" type="search" placeholder="' + esc( placeholder ) + '" value="' + esc( value ) + '" autocomplete="off" maxlength="100">' +
			'<button type="button" class="tbtp-search__clear" data-tbtp="clear" aria-label="Clear the search"' + ( value ? '' : ' hidden' ) + '>×</button>' +
		'</div>';
	}

	/**
	 * Text for matching: lower case, without accents. NFD splits most Polish
	 * letters into a base letter and a mark; ł has no decomposition, so it is
	 * mapped by hand.
	 */
	function fold( value ) {
		return String( value == null ? '' : value ).toLowerCase().normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).replace( /ł/g, 'l' );
	}

	function stat( value, label, big ) {
		return '<div class="tbtp-stat' + ( big ? ' tbtp-stat--big' : '' ) + '"><div class="tbtp-stat__v">' + esc( value ) +
			'</div><div class="tbtp-stat__k">' + esc( label ) + '</div></div>';
	}

	function errorLine( text ) {
		return '<p class="tbtp-error" role="alert">' + esc( text ) + '</p>';
	}

	function historyRows( items ) {
		return items.map( function ( h ) {
			var cls = h.points > 0 ? 'tbtp-plus' : h.points < 0 ? 'tbtp-minus' : '';
			return '<tr>' +
				'<td class="tbtp-when"><time datetime="' + esc( h.when ) + '">' + esc( h.when_text ) + '</time></td>' +
				'<td><span class="tbtp-tool">' + esc( h.label ) + '</span></td>' +
				'<td class="tbtp-what"><span class="tbtp-act">' + esc( h.title ) + '</span>' +
					( h.detail ? '<span class="tbtp-muted"> · ' + esc( h.detail ) + '</span>' : '' ) + '</td>' +
				'<td class="tbtp-num tbtp-right ' + cls + '">' + esc( signed( h.points ) ) + '</td>' +
			'</tr>';
		} ).join( '' );
	}

	function historyTable( items, emptyText ) {
		if ( ! items.length ) {
			return '<p class="tbtp-empty">' + esc( emptyText ) + '</p>';
		}
		return '<div class="tbtp-table-wrap"><table class="tbtp-table"><thead><tr>' +
			'<th scope="col">When</th><th scope="col">What</th><th scope="col"><span class="tbtp-sr">Activity</span></th>' +
			'<th scope="col" class="tbtp-right">Points</th></tr></thead><tbody>' + historyRows( items ) + '</tbody></table></div>';
	}

	function moreButton( hasMore ) {
		return hasMore ? '<div class="tbtp-more"><button type="button" class="tbtp-btn" data-tbtp="more">Show more</button></div>' : '';
	}

	/**
	 * A paged history list: holds the rows shown so far and loads the next
	 * page into the same table.
	 */
	function History( path, slot, emptyText ) {
		this.path = path;
		this.slot = slot;
		this.emptyText = emptyText;
		this.items = [];
		this.page = 1;
		this.hasMore = false;
	}

	History.prototype.take = function ( data, append ) {
		this.items = append ? this.items.concat( data.history || [] ) : ( data.history || [] );
		this.hasMore = !! data.has_more;
		this.paint();
	};

	History.prototype.paint = function () {
		var el = $( this.slot );
		if ( el ) {
			el.innerHTML = historyTable( this.items, this.emptyText ) + moreButton( this.hasMore ) + '<div data-slot="more-error"></div>';
		}
	};

	History.prototype.more = function () {
		var self = this;
		var btn = $( this.slot + ' [data-tbtp="more"]' );
		if ( btn ) {
			btn.disabled = true;
		}
		api( this.path, { page: this.page + 1 } ).then( function ( data ) {
			self.page += 1;
			self.take( data, true );
		}, function ( err ) {
			if ( btn ) {
				btn.disabled = false;
			}
			var e = $( self.slot + ' [data-slot="more-error"]' );
			if ( e ) {
				e.innerHTML = errorLine( errorText( err ) );
			}
		} );
	};

	/* =====================================================================
	 * Student
	 * ===================================================================== */

	function studentPage() {
		var list = new History( 'me', '[data-slot="history"]', 'Nothing yet. Finish a test, a deck, a game or a drill and your points appear here.' );

		root.innerHTML = adminBar( 'My points' ) + '<div data-slot="body"><p class="tbtp-loading" role="status">Loading points…</p></div>';

		api( 'me' ).then( function ( data ) {
			$( '[data-slot="body"]' ).innerHTML =
				'<div class="tbtp-stats">' +
					stat( num( data.balance ), 'points to spend', true ) +
					stat( signed( data.week ) || '0', 'earned this week' ) +
				'</div>' +
				'<section class="tbtp-stage"><h2 class="tbtp-stage__title">History</h2><div data-slot="history"></div></section>';
			list.take( data, false );
		}, function ( err ) {
			$( '[data-slot="body"]' ).innerHTML = errorLine( errorText( err ) );
		} );

		root.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-tbtp]' );
			if ( t && t.getAttribute( 'data-tbtp' ) === 'more' ) {
				list.more();
			}
		} );
	}

	/* =====================================================================
	 * Teacher
	 * ===================================================================== */

	function readState() {
		var q = new URLSearchParams( window.location.search );
		var sid = parseInt( q.get( P.student ) || '', 10 );
		var tab = IS_ADMIN && q.get( P.tab ) === 'rules' ? 'rules' : 'students';
		return {
			tab: tab,
			student: tab === 'students' && sid > 0 ? sid : 0,
			q: tab === 'students' ? ( q.get( P.q ) || '' ).trim().slice( 0, 100 ) : '',
		};
	}

	/**
	 * Write a view to the URL. A new view adds a history entry; a search
	 * change replaces the current one.
	 */
	function go( st, replace ) {
		var q = new URLSearchParams( window.location.search );
		q.delete( P.tab );
		q.delete( P.student );
		q.delete( P.q );
		if ( st.tab === 'rules' ) {
			q.set( P.tab, 'rules' );
		} else {
			if ( st.student ) {
				q.set( P.student, String( st.student ) );
			}
			if ( st.q ) {
				q.set( P.q, st.q );
			}
		}
		var s = q.toString();
		var url = window.location.pathname + ( s ? '?' + s : '' ) + window.location.hash;
		if ( replace ) {
			window.history.replaceState( null, '', url );
		} else {
			window.history.pushState( null, '', url );
		}
	}

	function tabs( current ) {
		if ( ! IS_ADMIN ) {
			return '';
		}
		return '<div class="tbtp-seg" role="group" aria-label="Sections">' +
			[ [ 'students', 'Students' ], [ 'rules', 'Rules' ] ].map( function ( t ) {
				return '<button type="button" data-tbtp="tab" data-tab="' + t[ 0 ] + '" aria-pressed="' + ( t[ 0 ] === current ? 'true' : 'false' ) + '">' + t[ 1 ] + '</button>';
			} ).join( '' ) + '</div>';
	}

	var T = {
		st: readState(),
		students: [],
		open: 0,        // student whose "Give or take points" row is open
		history: null,  // History for the one-student view
		loaded: false,  // the Students list has arrived
		urlTimer: 0,    // the search reaches the URL 300 ms after typing stops
	};

	function teacherPage() {
		clearTimeout( T.urlTimer );
		T.urlTimer = 0;
		T.st = readState();
		T.open = 0;
		T.loaded = false;
		// The search belongs to the Students list only: not Rules, not one
		// student's history.
		var search = T.st.tab === 'students' && ! T.st.student ? searchBox( 'Search a student…', T.st.q ) : '';
		root.innerHTML = adminBar( 'Points', search + tabs( T.st.tab ) ) + '<div data-slot="body"><p class="tbtp-loading" role="status">Loading points…</p></div>';
		if ( T.st.tab === 'rules' ) {
			loadRules();
		} else if ( T.st.student ) {
			loadStudent( T.st.student );
		} else {
			loadStudents();
		}
	}

	function body( html ) {
		$( '[data-slot="body"]' ).innerHTML = html;
	}

	/* ---------- Students tab ---------- */

	function loadStudents() {
		api( 'teacher/students' ).then( function ( data ) {
			T.students = data.students || [];
			T.loaded = true;
			var s = data.stats || {};
			body(
				'<div class="tbtp-stats">' +
					stat( num( s.earned_30 ), 'points earned in the last 30 days' ) +
					stat( ( s.earning || 0 ) + ' of ' + ( s.of || 0 ), 'students earning' ) +
				'</div>' +
				'<section class="tbtp-stage"><div class="tbtp-stage__head"><h2 class="tbtp-stage__title">Students</h2>' +
					'<p class="tbtp-summary" data-slot="count" aria-live="polite"></p></div><div data-slot="students"></div>' +
				'<p class="tbtp-note">Use a minus number to take points away (e.g. −20). The student sees the reason in their history. Each student\'s full history opens from their name.</p>' +
				'</section>'
			);
			paintStudents();
		}, function ( err ) {
			body( errorLine( errorText( err ) ) );
		} );
	}

	/** The students the search leaves: name or class title, accents ignored. */
	function shownStudents() {
		var needle = fold( T.st.q.trim() );
		if ( ! needle ) {
			return T.students;
		}
		return T.students.filter( function ( s ) {
			return fold( s.name ).indexOf( needle ) >= 0 || fold( s.class_title ).indexOf( needle ) >= 0;
		} );
	}

	function paintStudents() {
		var el = $( '[data-slot="students"]' );
		var count = $( '[data-slot="count"]' );
		if ( ! el ) {
			return;
		}
		var shown = shownStudents();
		var searching = T.st.q.trim() !== '';
		if ( count ) {
			count.textContent = searching ? shown.length + ' of ' + T.students.length + ' ' + ( T.students.length === 1 ? 'student' : 'students' ) : '';
		}
		if ( ! T.students.length ) {
			el.innerHTML = '<p class="tbtp-empty">No students yet. Students appear here once they are in one of your classes in TBT Notes.</p>';
			return;
		}
		if ( ! shown.length ) {
			el.innerHTML = '<p class="tbtp-empty">No student matches “' + esc( T.st.q.trim() ) + '”.</p>';
			return;
		}
		el.innerHTML = '<div class="tbtp-table-wrap"><table class="tbtp-table"><thead><tr>' +
			'<th scope="col">Student</th><th scope="col">Class</th><th scope="col">Balance</th><th scope="col">Last 30 days</th><th scope="col">Last earned</th><th scope="col"><span class="tbtp-sr">Actions</span></th>' +
			'</tr></thead><tbody>' + shown.map( studentRow ).join( '' ) + '</tbody></table></div>';
	}

	/** Filter now; write the URL once typing has stopped for 300 ms. */
	function setSearch( value, now ) {
		T.st.q = value.trim().slice( 0, 100 );
		var clear = $( '[data-tbtp="clear"]' );
		if ( clear ) {
			clear.hidden = ! value;
		}
		paintStudents();
		clearTimeout( T.urlTimer );
		T.urlTimer = 0;
		if ( now ) {
			go( T.st, true );
		} else {
			T.urlTimer = setTimeout( function () {
				T.urlTimer = 0;
				go( T.st, true );
			}, 300 );
		}
	}

	/**
	 * Write a search still waiting for its 300 ms to the current entry, so
	 * Back from the next view returns to the filtered list.
	 */
	function flushSearch() {
		if ( T.urlTimer ) {
			clearTimeout( T.urlTimer );
			T.urlTimer = 0;
			go( T.st, true );
		}
	}

	function openStudent( id ) {
		flushSearch();
		go( { tab: 'students', student: id, q: T.st.q } );
		teacherPage();
		window.scrollTo( 0, root.getBoundingClientRect().top + window.pageYOffset - 24 );
	}

	function studentRow( s ) {
		var row = '<tr>' +
			'<td><button type="button" class="tbtp-lnk tbtp-who" data-tbtp="student" data-id="' + s.id + '">' + esc( s.name ) + '</button></td>' +
			'<td class="tbtp-cls">' + esc( s.class_title || '–' ) + '</td>' +
			'<td class="tbtp-num"><b>' + esc( num( s.balance ) ) + '</b></td>' +
			'<td class="tbtp-num">' + esc( s.earned_30 ? signed( s.earned_30 ) : '0' ) + '</td>' +
			'<td class="tbtp-when">' + ( s.last_earned ? '<time datetime="' + esc( s.last_earned.when ) + '">' + esc( s.last_earned.when_text ) + '</time>' : '–' ) + '</td>' +
			'<td class="tbtp-right"><button type="button" class="tbtp-btn tbtp-btn--small" data-tbtp="adjust" data-id="' + s.id + '" aria-expanded="' + ( T.open === s.id ? 'true' : 'false' ) + '">Give or take points</button></td>' +
		'</tr>';
		if ( T.open === s.id ) {
			row += '<tr class="tbtp-adjust-row"><td colspan="6">' + adjustForm( s ) + '</td></tr>';
		}
		return row;
	}

	function adjustForm( s ) {
		return '<form class="tbtp-adjust" data-tbtp-form="adjust" data-id="' + s.id + '" novalidate>' +
			'<span class="tbtp-field"><label class="tbtp-sr" for="tbtp-adj-points">Points for ' + esc( s.name ) + '</label>' +
				'<input id="tbtp-adj-points" class="tbtp-inp tbtp-inp--n" type="text" inputmode="text" autocomplete="off" maxlength="6" placeholder="+20" name="points" required></span>' +
			'<span class="tbtp-field tbtp-field--grow"><label class="tbtp-sr" for="tbtp-adj-reason">Reason</label>' +
				'<input id="tbtp-adj-reason" class="tbtp-inp" type="text" maxlength="190" placeholder="Reason, e.g. Great speaking in class" name="reason" required></span>' +
			'<button type="submit" class="tbtp-btn tbtp-btn--primary">Save</button>' +
			'<button type="button" class="tbtp-btn" data-tbtp="cancel">Cancel</button>' +
			'<div class="tbtp-form-msg" data-slot="adjust-msg" aria-live="polite"></div>' +
		'</form>';
	}

	function saveAdjust( form ) {
		var id = parseInt( form.getAttribute( 'data-id' ), 10 );
		var pointsEl = form.querySelector( '[name="points"]' );
		var reasonEl = form.querySelector( '[name="reason"]' );
		var msg = form.querySelector( '[data-slot="adjust-msg"]' );
		var submit = form.querySelector( '[type="submit"]' );
		// "+20", "-10" and a typographic minus ("−10") are all accepted.
		var raw = String( pointsEl.value || '' ).trim().replace( /^\+/, '' ).replace( /^[−–]/, '-' );

		var errors = {};
		var n = /^-?\d+$/.test( raw ) ? parseInt( raw, 10 ) : NaN;
		if ( isNaN( n ) || n === 0 || n < -1000 || n > 1000 ) {
			errors.points = 'A whole number from −1000 to 1000, not 0.';
		}
		var reason = reasonEl.value.trim();
		if ( reason.length < 3 || reason.length > 190 ) {
			errors.reason = 'Write a reason, 3 to 190 characters.';
		}
		if ( showAdjustErrors( form, errors ) ) {
			return;
		}

		submit.disabled = true;
		api( 'teacher/adjust', null, { user_id: id, points: n, reason: reason } ).then( function () {
			T.open = 0;
			loadStudents();
		}, function ( err ) {
			submit.disabled = false;
			if ( err.errors ) {
				showAdjustErrors( form, err.errors );
			} else {
				msg.innerHTML = errorLine( errorText( err ) );
			}
		} );
	}

	function showAdjustErrors( form, errors ) {
		var msg = form.querySelector( '[data-slot="adjust-msg"]' );
		var keys = Object.keys( errors );
		[ 'points', 'reason' ].forEach( function ( k ) {
			var el = form.querySelector( '[name="' + k + '"]' );
			if ( errors[ k ] ) {
				el.setAttribute( 'aria-invalid', 'true' );
			} else {
				el.removeAttribute( 'aria-invalid' );
			}
		} );
		msg.innerHTML = keys.map( function ( k ) {
			return '<span class="tbtp-field-error">' + esc( errors[ k ] ) + '</span>';
		} ).join( '' );
		if ( keys.length ) {
			var first = form.querySelector( '[name="' + keys[ 0 ] + '"]' );
			if ( first ) {
				first.focus();
			}
		}
		return keys.length > 0;
	}

	/* ---------- One student ---------- */

	function loadStudent( id ) {
		T.history = new History( 'teacher/student/' + id, '[data-slot="history"]', 'No points yet.' );
		api( 'teacher/student/' + id ).then( function ( data ) {
			var s = data.student || {};
			body(
				'<button type="button" class="tbtp-back" data-tbtp="home">← All students</button>' +
				'<div class="tbtp-rhead"><h2 class="tbtp-rhead__title">' + esc( s.name ) + '</h2>' +
					'<p class="tbtp-muted">' + ( s.class_title ? esc( s.class_title ) + ' · ' : '' ) + esc( num( s.balance ) ) + ' points</p></div>' +
				'<section class="tbtp-stage"><h2 class="tbtp-stage__title">History</h2><div data-slot="history"></div></section>'
			);
			T.history.take( data, false );
		}, function ( err ) {
			body( '<button type="button" class="tbtp-back" data-tbtp="home">← All students</button>' + errorLine( errorText( err ) ) );
		} );
	}

	/* ---------- Rules tab ---------- */

	function numInput( name, value, label ) {
		return '<input class="tbtp-inp tbtp-inp--n" type="number" step="1" min="0" max="1000" name="' + esc( name ) + '" id="tbtp-r-' + esc( name.replace( '.', '-' ) ) + '" value="' + esc( value ) + '" aria-label="' + esc( label ) + '">';
	}

	function errSlot( name ) {
		return '<span class="tbtp-field-error" data-err="' + esc( name ) + '" hidden></span>';
	}

	function loadRules() {
		api( 'rules' ).then( function ( data ) {
			var r = data.rules || {};
			var labels = data.labels || {};
			var tools = TOOL_ORDER.filter( function ( k ) {
				return r.tools && k in r.tools;
			} );
			body(
				'<section class="tbtp-stage"><h2 class="tbtp-stage__title">How students earn points</h2>' +
				'<p class="tbtp-sub">Points are added automatically when a result reaches TBT Results. These are starting numbers; change any of them.</p>' +
				'<form data-tbtp-form="rules" novalidate>' +
				'<div class="tbtp-table-wrap"><table class="tbtp-table"><thead><tr><th scope="col">Activity</th><th scope="col">Points for finishing</th><th scope="col">Note</th></tr></thead><tbody>' +
				tools.map( function ( k ) {
					var label = labels[ k ] || k;
					return '<tr><td><span class="tbtp-tool">' + esc( label ) + '</span></td><td>' + numInput( 'tools.' + k, r.tools[ k ], label + ' points' ) + errSlot( 'tools.' + k ) + '</td>' +
						'<td class="tbtp-muted">' + esc( TOOL_NOTES[ k ] || '' ) + '</td></tr>';
				} ).join( '' ) +
				'<tr><td><span class="tbtp-tool">Any other activity</span></td><td>' + numInput( 'unknown', r.unknown, 'Points for any other activity' ) + errSlot( 'unknown' ) + '</td>' +
					'<td class="tbtp-muted">A tool not in this list</td></tr>' +
				'</tbody></table></div>' +
				'<div class="tbtp-table-wrap"><table class="tbtp-table tbtp-table--rules"><tbody>' +
				'<tr><td>Score bonus</td><td class="tbtp-inline">' + numInput( 'bonus', r.bonus, 'Bonus points' ) + ' points at ' +
					'<input class="tbtp-inp tbtp-inp--n" type="number" step="1" min="1" max="100" name="bonus_at" value="' + esc( r.bonus_at ) + '" aria-label="Bonus threshold, percent"> % or more' +
					errSlot( 'bonus' ) + errSlot( 'bonus_at' ) + '</td><td class="tbtp-muted">Only for results with a score out of a maximum</td></tr>' +
				'<tr><td>Doing the same activity again</td><td class="tbtp-inline">' + numInput( 'repeat', r.repeat, 'Repeat points' ) + ' points' + errSlot( 'repeat' ) + '</td>' +
					'<td class="tbtp-muted">Once a day per activity; first time earns full points</td></tr>' +
				'<tr><td>First activity of the day</td><td class="tbtp-inline">' + numInput( 'day', r.day, 'First activity of the day bonus' ) + ' points' + errSlot( 'day' ) + '</td>' +
					'<td class="tbtp-muted">Rewards coming back every day</td></tr>' +
				'<tr><td>Daily limit</td><td class="tbtp-inline">' + numInput( 'daily_cap', r.daily_cap, 'Daily limit' ) + ' points a day' + errSlot( 'daily_cap' ) + '</td>' +
					'<td class="tbtp-muted">Your own awards don\'t count towards it</td></tr>' +
				'<tr><td>Points start on</td><td><input class="tbtp-inp tbtp-inp--date" type="date" name="start" value="' + esc( r.start ) + '" aria-label="Start date">' + errSlot( 'start' ) + '</td>' +
					'<td class="tbtp-muted">Work finished before this date earns nothing</td></tr>' +
				'</tbody></table></div>' +
				'<div class="tbtp-actions"><button type="submit" class="tbtp-btn tbtp-btn--primary">Save rules</button><span class="tbtp-saved" data-slot="rules-msg" aria-live="polite"></span></div>' +
				'</form></section>'
			);
		}, function ( err ) {
			body( errorLine( errorText( err ) ) );
		} );
	}

	function saveRules( form ) {
		var data = { tools: {} };
		Array.prototype.forEach.call( form.querySelectorAll( 'input[name]' ), function ( el ) {
			var name = el.name;
			var value = el.type === 'date' ? el.value : String( el.value ).trim();
			if ( name.indexOf( 'tools.' ) === 0 ) {
				data.tools[ name.slice( 6 ) ] = value;
			} else {
				data[ name ] = value;
			}
		} );
		var submit = form.querySelector( '[type="submit"]' );
		var msg = form.querySelector( '[data-slot="rules-msg"]' );
		submit.disabled = true;
		msg.textContent = '';
		showRuleErrors( form, {} );

		api( 'rules', null, data ).then( function () {
			submit.disabled = false;
			msg.className = 'tbtp-saved';
			msg.textContent = 'Saved';
			setTimeout( function () {
				if ( msg.textContent === 'Saved' ) {
					msg.textContent = '';
				}
			}, 3000 );
		}, function ( err ) {
			submit.disabled = false;
			if ( err.errors ) {
				showRuleErrors( form, err.errors );
				msg.className = 'tbtp-field-error';
				msg.textContent = 'Nothing was saved. Check the fields marked in red.';
			} else {
				msg.className = 'tbtp-field-error';
				msg.textContent = errorText( err );
			}
		} );
	}

	function showRuleErrors( form, errors ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-err]' ), function ( el ) {
			var k = el.getAttribute( 'data-err' );
			var input = form.querySelector( '[name="' + k + '"]' );
			if ( errors[ k ] ) {
				el.textContent = errors[ k ];
				el.hidden = false;
				if ( input ) {
					input.setAttribute( 'aria-invalid', 'true' );
				}
			} else {
				el.textContent = '';
				el.hidden = true;
				if ( input ) {
					input.removeAttribute( 'aria-invalid' );
				}
			}
		} );
		var first = form.querySelector( '[aria-invalid="true"]' );
		if ( first ) {
			first.focus();
		}
	}

	/* ---------- Teacher events ---------- */

	function teacherEvents() {
		root.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-tbtp]' );
			if ( ! t ) {
				return;
			}
			var act = t.getAttribute( 'data-tbtp' );
			var id = parseInt( t.getAttribute( 'data-id' ) || '', 10 );
			if ( act === 'tab' ) {
				flushSearch();
				go( { tab: t.getAttribute( 'data-tab' ), student: 0, q: T.st.q } );
				teacherPage();
			} else if ( act === 'student' ) {
				openStudent( id );
			} else if ( act === 'home' ) {
				go( { tab: 'students', student: 0, q: T.st.q } );
				teacherPage();
			} else if ( act === 'clear' ) {
				var box = $( '#tbtp-q' );
				if ( box ) {
					box.value = '';
					box.focus();
				}
				setSearch( '', true );
			} else if ( act === 'adjust' ) {
				T.open = T.open === id ? 0 : id;
				paintStudents();
				var input = $( '#tbtp-adj-points' );
				if ( input ) {
					input.focus();
				}
			} else if ( act === 'cancel' ) {
				var back = T.open;
				T.open = 0;
				paintStudents();
				var btn = $( '[data-tbtp="adjust"][data-id="' + back + '"]' );
				if ( btn ) {
					btn.focus();
				}
			} else if ( act === 'more' && T.history ) {
				T.history.more();
			}
		} );

		root.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( '[data-tbtp-form]' );
			if ( ! form ) {
				return;
			}
			e.preventDefault();
			if ( form.getAttribute( 'data-tbtp-form' ) === 'adjust' ) {
				saveAdjust( form );
			} else {
				saveRules( form );
			}
		} );

		root.addEventListener( 'input', function ( e ) {
			if ( e.target.id === 'tbtp-q' ) {
				setSearch( e.target.value, false );
			}
		} );

		root.addEventListener( 'keydown', function ( e ) {
			if ( e.target.id === 'tbtp-q' ) {
				if ( e.key === 'Escape' && e.target.value ) {
					e.target.value = '';
					setSearch( '', true );
				} else if ( e.key === 'Enter' ) {
					e.preventDefault();
					var shown = T.loaded ? shownStudents() : [];
					if ( T.st.q && shown.length === 1 ) {
						openStudent( shown[ 0 ].id );
					}
				}
				return;
			}
			if ( e.key === 'Escape' && T.open && e.target.closest( '[data-tbtp-form="adjust"]' ) ) {
				var cancel = $( '[data-tbtp="cancel"]' );
				if ( cancel ) {
					cancel.click();
				}
			}
		} );

		window.addEventListener( 'popstate', teacherPage );
	}

	/* ---------- Start ---------- */

	if ( MODE === 'teacher' ) {
		teacherEvents();
		teacherPage();
	} else {
		studentPage();
	}
}() );
