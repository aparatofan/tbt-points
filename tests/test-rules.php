<?php
/**
 * Standalone tests for TBTP_Rules: the award calculation, validation and
 * day bounds. WordPress is not loaded.
 *
 * Run: php tests/test-rules.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-tbtp-rules.php';

$failures = 0;
$count    = 0;

function check( $label, $actual, $expected ) {
	global $failures, $count;
	++$count;
	if ( $actual === $expected ) {
		echo "ok   - {$label}\n";
		return;
	}
	++$failures;
	echo "FAIL - {$label}\n       expected " . var_export( $expected, true ) . "\n       got      " . var_export( $actual, true ) . "\n";
}

/** Award with the default rules and these inputs over a fresh-day baseline. */
function award( array $in, array $rules = array() ) {
	$base = array(
		'tool'         => 'swipe',
		'percent'      => null,
		'is_first'     => true,
		'repeat_today' => false,
		'day_given'    => false,
		'earned_today' => 0,
	);
	return TBTP_Rules::award( array_replace_recursive( TBTP_Rules::defaults(), $rules ), array_replace( $base, $in ) );
}

function out( $earn, $day, $detail = '' ) {
	return array(
		'earn'        => $earn,
		'earn_detail' => $detail,
		'day'         => $day,
	);
}

/* ---------- First time ---------- */

check( 'test, first time, 79 %: no bonus', award( array( 'tool' => 'test', 'percent' => 79 ) ), out( 10, 2 ) );
check( 'test, first time, 80 %: bonus', award( array( 'tool' => 'test', 'percent' => 80 ) ), out( 15, 2 ) );
check( 'homework 8 / 10 (80 %): 15 + 5', award( array( 'tool' => 'homework', 'percent' => 80 ) ), out( 20, 2 ) );
check( 'unscored tool (swipe): no bonus', award( array( 'tool' => 'swipe', 'percent' => null ) ), out( 5, 2 ) );
check( 'unknown tool: unknown points', award( array( 'tool' => 'quiz', 'percent' => null ) ), out( 5, 2 ) );
check( 'unknown tool with its own number', award( array( 'tool' => 'quiz' ), array( 'unknown' => 7 ) ), out( 7, 2 ) );
check( 'fill: score without max is no percent, no bonus', award( array( 'tool' => 'fill', 'percent' => null ) ), out( 5, 2 ) );
check( 'lesson (Learning History): its own rule', award( array( 'tool' => 'lesson' ), array( 'tools' => array( 'lesson' => 2 ) ) ), out( 2, 2 ) );
check( 'lesson: no score, no bonus', award( array( 'tool' => 'lesson', 'percent' => null ) ), out( 5, 2 ) );
check( 'poll: its own rule (default 3)', award( array( 'tool' => 'poll' ) ), out( 3, 2 ) );
check( 'poll: changed rule', award( array( 'tool' => 'poll' ), array( 'tools' => array( 'poll' => 4 ) ) ), out( 4, 2 ) );
check( 'poll: no score, no bonus', award( array( 'tool' => 'poll', 'percent' => null, 'day_given' => true ) ), out( 3, 0 ) );
check( 'changed rule: swipe 3', award( array( 'tool' => 'swipe' ), array( 'tools' => array( 'swipe' => 3 ) ) ), out( 3, 2 ) );
check( '0 % is a real result, still no bonus', award( array( 'tool' => 'test', 'percent' => 0 ) ), out( 10, 2 ) );

/* ---------- Repeats ---------- */

check( 'repeat, first today: 1 point, again', award( array( 'is_first' => false ) ), out( 1, 2, 'again' ) );
check( 'repeat, already repeated today: 0', award( array( 'is_first' => false, 'repeat_today' => true, 'day_given' => true ) ), out( 0, 0, 'again' ) );
check( 'repeat earns no score bonus', award( array( 'tool' => 'test', 'percent' => 100, 'is_first' => false, 'day_given' => true ) ), out( 1, 0, 'again' ) );

/* ---------- Day bonus ---------- */

check( 'day bonus once: given already', award( array( 'day_given' => true ) ), out( 5, 0 ) );
check( 'day bonus 0 in the rules', award( array(), array( 'day' => 0 ) ), out( 5, 0 ) );

/* ---------- Daily cap ---------- */

check( 'cap cuts earn (47 earned, test 15)', award( array( 'tool' => 'test', 'percent' => 90, 'earned_today' => 47, 'day_given' => true ) ), out( 3, 0 ) );
check( 'cap cuts day after earn (44 earned: 5 + 1)', award( array( 'earned_today' => 44 ) ), out( 5, 1 ) );
check( 'cap: earn fills the room, day 0 (45 earned)', award( array( 'earned_today' => 45 ) ), out( 5, 0 ) );
check( 'cap cuts both to 0 (50 earned)', award( array( 'earned_today' => 50 ) ), out( 0, 0 ) );
check( 'cap already passed (60 earned)', award( array( 'earned_today' => 60 ) ), out( 0, 0 ) );
check( 'cap 0 earns nothing', award( array(), array( 'daily_cap' => 0 ) ), out( 0, 0 ) );

/* ---------- Validation ---------- */

$good = TBTP_Rules::defaults();
$good['start'] = '2026-11-17';
$v = TBTP_Rules::validate( $good );
check( 'defaults with a start validate', $v['errors'], array() );

$v = TBTP_Rules::validate( array_replace( $good, array( 'bonus_at' => '0', 'daily_cap' => 1001, 'repeat' => '1.5', 'start' => '2026-02-30' ) ) );
check( 'bad fields reported per field', array_keys( $v['errors'] ), array( 'bonus_at', 'repeat', 'daily_cap', 'start' ) );

$bad          = $good;
$bad['tools'] = array( 'swipe' => -1 ) + $good['tools'];
$v            = TBTP_Rules::validate( $bad );
check( 'a tool below 0 is refused', array_keys( $v['errors'] ), array( 'tools.swipe' ) );

$v = TBTP_Rules::validate( array_replace( $good, array( 'day' => '3', 'bonus_at' => '100' ) ) );
check( 'digit strings accepted', array( $v['errors'], $v['rules']['day'], $v['rules']['bonus_at'] ), array( array(), 3, 100 ) );

/* Rules saved before 0.1.2 have no `lesson`: it falls back to the default
   and every saved number is kept. */
$old = TBTP_Rules::merge( array( 'tools' => array( 'swipe' => 3, 'test' => 12 ), 'start' => '2026-11-14' ) );
check( 'old saved rules: lesson gets the default, others kept', array( $old['tools']['lesson'], $old['tools']['swipe'], $old['tools']['test'] ), array( 5, 3, 12 ) );

/* Rules saved before 0.1.3 have no `poll`: it gets the default (3) and every
   saved number is kept. */
$old = TBTP_Rules::merge( array( 'tools' => array( 'swipe' => 3, 'test' => 12, 'lesson' => 2 ), 'start' => '2026-11-14' ) );
check( 'old saved rules: poll gets the default, others kept', array( $old['tools']['poll'], $old['tools']['lesson'], $old['tools']['swipe'], $old['tools']['test'] ), array( 3, 2, 3, 12 ) );

/* Dictionary words (0.1.4): their own rule, no score so no bonus. */
check( 'dictionary_word: its own rule (default 1)', award( array( 'tool' => 'dictionary_word' ) ), out( 1, 2 ) );
check( 'dictionary_word: changed rule', award( array( 'tool' => 'dictionary_word' ), array( 'tools' => array( 'dictionary_word' => 2 ) ) ), out( 2, 2 ) );
check( 'dictionary_word: no score, no bonus', award( array( 'tool' => 'dictionary_word', 'percent' => null, 'day_given' => true ) ), out( 1, 0 ) );
check( 'dictionary_word label', TBTP_Rules::tool_label( 'dictionary_word' ), 'Dictionary: new word' );

/* Direct events (tbt_points_record_event()): once ever, no score bonus. */
function direct( array $in, array $rules = array() ) {
	$base = array(
		'tool'         => 'dictionary_word',
		'percent'      => null,
		'is_first'     => true,
		'repeat_today' => false,
		'day_given'    => false,
		'earned_today' => 0,
	);
	return TBTP_Rules::award_direct( array_replace_recursive( TBTP_Rules::defaults(), $rules ), array_replace( $base, $in ) );
}
check( 'direct: first time earns the rule plus the day bonus', direct( array() ), out( 1, 2 ) );
check( 'direct: day bonus already given', direct( array( 'day_given' => true ) ), out( 1, 0 ) );
check( 'direct: a percent never adds the score bonus', direct( array( 'percent' => 100 ) ), out( 1, 2 ) );
check( 'direct: earned before → nothing, no repeat', direct( array( 'is_first' => false ) ), out( 0, 0 ) );
check( 'direct: earned before, repeat not yet today → still nothing', direct( array( 'is_first' => false, 'repeat_today' => false, 'day_given' => false ) ), out( 0, 0 ) );
check( 'direct: daily cap applies', direct( array( 'earned_today' => 50 ) ), out( 0, 0 ) );
check( 'direct: cap leaves room for the word only', direct( array( 'earned_today' => 49 ) ), out( 1, 0 ) );
check( 'direct: unknown tool uses the unknown rate', direct( array( 'tool' => 'something_new' ) ), out( 5, 2 ) );

/* Rules saved before 0.1.4 have no `dictionary_word`: it gets the default (1)
   and every saved number is kept. */
$old = TBTP_Rules::merge( array( 'tools' => array( 'swipe' => 3, 'test' => 12, 'lesson' => 2, 'poll' => 4 ), 'unknown' => 7, 'start' => '2026-11-14' ) );
check( 'old saved rules: dictionary_word gets the default, others kept', array( $old['tools']['dictionary_word'], $old['tools']['poll'], $old['tools']['lesson'], $old['tools']['swipe'], $old['tools']['test'], $old['unknown'] ), array( 1, 4, 2, 3, 12, 7 ) );

/* ---------- Day bounds ---------- */

$waw = new DateTimeZone( 'Europe/Warsaw' );
check( 'day bounds, winter (UTC+1)', TBTP_Rules::day_bounds( '2026-11-14', $waw ), array( '2026-11-13 23:00:00', '2026-11-14 23:00:00' ) );
check( 'day bounds across DST end (25 h day)', TBTP_Rules::day_bounds( '2026-10-25', $waw ), array( '2026-10-24 22:00:00', '2026-10-25 23:00:00' ) );

echo "\n{$count} checks, {$failures} failed.\n";
exit( $failures ? 1 : 0 );
