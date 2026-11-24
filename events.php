<?php
/**
 *     __                         __
 *    / /_  _________ _____  ____/ /_____________
 *   / __ \/ ___/ __ `/ __ \/ __  / ___/ ___/ __ \
 *  / /_/ / /  / /_/ / / / / /_/ / /  / /__/ /_/ /
 * /_.___/_/   \__,_/_/ /_/\__,_/_/   \___/\____/
 *
 * Designed + Developed
 * by Kaleb Heitzman
 * https://brandr.co
 *
 * (c) 2016
 */
namespace Grav\Plugin;

// import classes
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/classes/iCalendarProcessor.php';
require_once __DIR__.'/classes/calendarProcessor.php';
require_once __DIR__.'/classes/eventsProcessor.php';

use Grav\Common\Page\Header;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

use Carbon\Carbon;

/**
 * Grav Events
 *
 * The Events Plugin provides Event Listings and Calendars for your Grav
 * powered website. This plugin searches each page for `event:` frontmatter
 * and then sets a custom taxonomy named *type* to *event*. It also sets
 * a repeating and frequency taxonomy to build more intricate collections.
 * The `event_repeat` taxonomy will take a string in the format `MTWRFSU` and
 * the `event_freq` taxonomy will take `daily, weekly, monthly, or yearly`.
 * These taxonomies are automatically added and processed by the plugin.
 *
 * Below is a sample of what an `event:` front matter section would look like in
 * a Grav page. Note: You can used the event template and yaml included in the
 * plugin for use in the admin plugin or add `event:` frontmatter to any page of
 * your choice. This plugin is smart enough to add any page to `@taxonomy.type`
 * as an event so you can build collections off of pages taxonomized with the
 * __event__ taxonomy type.
 *
 * ```
 * event:
 *     start: 01/01/2015 6:00pm
 *     end: 01/01/2015 7:00pm
 *     repeat: MTWRFSU
 *     freq: weekly
 *     until: 01/01/2020
 *     location: Raleigh, NC
 *     coordinates: 35.7795897, -78.6381787
 * ```
 *
 * If you use the Admin pluin, the events plugin will automatically geo-decode
 * the location field to a set of coordinates so that you don't have too.
 *
 * PHP version 8.3+ (Grav 2.0)
 *
 * @package    Events
 * @author     Kaleb Heitzman <kalebheitzman@gmail.com>
 * @copyright  2016 Kaleb Heitzman
 * @license    https://opensource.org/licenses/MIT MIT
 * @version    1.2.0
 * @link       https://github.com/kalebheitzman/grav-plugin-events
 * @since      1.0.0 Initial Release
 */
class EventsPlugin extends Plugin
{
	/**
	 * Current Carbon DateTime
	 *
	 * @since  1.0.0 Initial Release
	 * @var    object Carbon DateTime
	 */
	protected $now;

	/**
	 * Events/Events Class
	 *
	 * Processes pages for `event:` frontmatter and then inserts repeating and
	 * reoccuring events into Grav Pages with updated dates, route, and path.
	 *
	 * @since  1.0.0 Initial Release
	 * @var    object Events
	 */
	protected $events;

	/**
	 * Events/Calendar Class
	 *
	 * Provides data to be used in the `calendar.html.twig` template.
	 *
	 * @since  1.0.0 Initial Release
	 * @var    object Calendar
	 */
	protected $calendar;

	/**
	 * Get Subscribed Events
	 *
	 * @since  1.0.0 Initial Release
	 * @return array
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onPluginsInitialized' => ['onPluginsInitialized', 0],
			'onGetPageTemplates'   => ['onGetPageTemplates', 0],
			'onTwigExtensions'     => ['onTwigExtensions', 0],
			// Subscribed unconditionally on purpose (Grav 2.0): Admin 2.0 saves
			// through the API plugin, where isAdmin() is still false while
			// plugins initialize. The event only fires during admin/API writes.
			'onAdminSave'          => ['onAdminSave', 0],
		];
	}

	/**
	 * Is the current request an admin or API (Admin 2.0) request?
	 *
	 * isAdmin() alone is not enough on Grav 2.0: the API plugin only sets up
	 * the admin context when it dispatches a route, which is after plugins have
	 * been initialized. So the request path is checked as well.
	 *
	 * @since  1.2.0 Grav 2.0 support
	 * @return bool
	 */
	protected function isBackendRequest(): bool
	{
		if ( $this->isAdmin() ) {
			return true;
		}

		$path = '/' . trim((string) $this->grav['uri']->path(), '/');
		$routes = ['/api', (string) $this->config->get('plugins.api.route')];

		foreach ( $routes as $route ) {
			$route = trim($route, '/');
			if ( $route === '' ) {
				continue;
			}
			$route = '/' . $route;
			if ( $path === $route || strpos($path, $route . '/') === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Initialize plugin configuration
	 *
	 * Determine if the plugin should run and set the custom
	 * taxonomies to store event information in. We also initialize the Events
	 * and Calendar class that this plugin utilizes and then we start
	 * intercepting Grav hooks to build our events list and insert any vars
	 * we need into the system.
	 *
	 * @since  1.0.0 Initial Release
	 * @return void
	 */
	public function onPluginsInitialized()
	{
		// Nothing else is needed for admin/API requests so close it out
		// (onAdminSave is already subscribed in getSubscribedEvents())
		if ( $this->isBackendRequest() ) {
			return;
		}

		// Add these to taxonomy for events management
		$event_taxonomies = array('type', 'event_freq', 'event_repeat', 'event_location');
		$taxonomy_config = array_merge((array)$this->config->get('site.taxonomies'), $event_taxonomies);
		$this->config->set('site.taxonomies', $taxonomy_config);

		// get the current datetime with c
		$this->now = Carbon::now();

		// set the calendar accessor
		$this->calendar = new \Events\CalendarProcessor();

		// set the events accessor
		$this->events = new \Events\EventsProcessor();

		// enable the following hooks
		$this->enable([
			'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
			'onPagesInitialized' => ['onPagesInitialized', 0],
			'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
		]);
	}

	/**
	 * Add current directory to twig lookup paths.
	 *
	 * Add the templates directory to the twig directory look up path so we
	 * can load our page templates. These are overridable by the theme and
	 * are only meant as a starting point.
	 *
	 * @since  1.0.0 Initial Release
	 * @return void
	 */
	public function onTwigTemplatePaths()
	{
		// add templates to twig path
		$this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
	}

	/**
	 * Add repeating and reoccuring events as Grav pages
	 *
	 * Repeating Events: events the tile horizontally in a week `MTWRFSU`
	 * Reoccuring Events: events that tile vertically through `daily, weekly,
	 * monthly, yearly`
	 *
	 * The Events/Events class searches for pages with `event:` frontmatter and
	 * processes these into new Grav pages as needed. This is a dynamic operation
	 * and does not add new physical pages to the filesystem.
	 *
	 * @since  1.0.0 Initial Release
	 * @return void
	 */
	public function onPagesInitialized()
	{
		// get instances of all events
		$this->events->all();
	}

	/**
	 * Association with page templates
	 *
	 * @param  Event $event
	 * @since  1.0.15 Major Refactor
	 * @return void
	 */
	public function onGetPageTemplates(Event $event)
	{
		$types = $event->types;

		/* @var Locator $locator */
		$locator = Grav::instance()['locator'];

		// Set blueprints & templates.
		$types->scanBlueprints($locator->findResource('plugin://events/blueprints'));
		$types->scanTemplates($locator->findResource('plugin://events/templates'));

		// reverse the FUBARd order of blueprints
		$event = array_reverse($types['event']);
		$types['event'] = $event;
	}

	/**
	 * Set needed variables to display events
	 *
	 * For the calendar page, we load the appropriate js and css to make the
	 * calendar work smoothly as well as add the appropriate calendar twig
	 * variables.
	 *
	 * @since  1.0.0 Initial Release
	 * @return void
	 */
	public function onTwigSiteVariables()
	{
		// setup
		$page = 			$this->grav['page'];
		$pages = 			$this->grav['pages'];
		$collection = 		$pages->all()->ofType('event');
		$twig = 			$this->grav['twig'];
		$assets = 			$this->grav['assets'];

		/** @var Uri $uri */
		$uri = 				$this->grav['uri'];
		$type = 			$uri->extension();

		// only load the vars if calendar page
		if ($page->template() == 'calendar')
		{
			$yearParam = $this->grav['uri']->param('year');
			$monthParam = $this->grav['uri']->param('month');

			$twigVars = $this->calendar->twigVars($yearParam, $monthParam);
			$calVars = $this->calendar->calendarVars($collection);

			// add calendar to twig as calendar
			$twigVars['calendar']['events'] = $calVars;
			$twig->twig_vars['calendar'] = array_shift($twigVars);

			if ( $type == 'json' ) {
				$twig->twig_vars['calendarJson'] = $this->calendar->calendarVars($collection);
			}
		}

		// output on events
		if ( $page->template() == 'events' && $type == 'json' ) {
			$events = [];
			foreach($collection as $page) {
				$events[]['id'] = $page->id();
				$events[]['title'] = $page->title();
				$events[]['date'] = $page->date();
				$events[]['header'] = $page->header();
				$events[]['content'] = $page->content();
				$events[]['summary'] = $page->summary();
				$events[]['lang'] = $page->language();
				$events[]['meta'] = $page->contentMeta();
				$events[]['slug'] = $page->slug();
				$events[]['permalink'] = $page->permalink();
			}
			$twig->twig_vars['eventsJson'] = $events;
		}

		$twig->twig_vars['eventCategories'] = $this->events->getEventCategories();

		// scripts
		$js = 'plugin://events/assets/events.min.js';
		$assets->add('jquery');
		$assets->addJs($js, 1);

		// styles
		$css = 'plugin://events/assets/events.min.css';
		$assets->addCss($css);
	}

	/**
	 * Process Event Information
	 *
	 * Fires before an object is saved by the classic admin or, on Grav 2.0,
	 * by the API plugin (Admin 2.0). For single events it runs a reverse
	 * geocoding for the location field. For the plugin settings it triggers
	 * the iCalendar import.
	 *
	 * @param  Event $event
	 * @since  1.0.15 Location Field Update
	 * @since  1.2.0  Grav 2.0 support
	 * @return void
	 */
	public function onAdminSave(Event $event)
	{
		$obj = $event['object'] ?? null;

		// pages (PageObject on Flex, Page on legacy, whatever the API hands over)
		if ( $obj instanceof PageInterface ) {
			if ( $obj->template() == 'event' ) {
				$this->geocodeEventPage($obj);
			}
			return;
		}

		// not a page, so it may be the plugin settings
		$this->handleSettingsSave($obj);
	}

	/**
	 * Reverse geocode the location of an event page
	 *
	 * @param  PageInterface $page
	 * @since  1.2.0 Grav 2.0 support (extracted from onAdminSave)
	 * @return void
	 */
	protected function geocodeEventPage(PageInterface $page)
	{
		$config = (array) $this->config->get('plugins.events');

		/** @var Header $header */
		$header = $page->header();
		$ev = $header->event ?? null;

		if ( ! is_array($ev) || empty($ev['location']) || ! empty($ev['coordinates']) ) {
			return;
		}

		// leave if geocoding is disabled
		if ( empty($config['enable_geocoding']) ) {
			return;
		}

		$location = $ev['location'];

		// build a url
		$url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($location) . "&key=" . ($config['api_key'] ?? '');

		// fetch the results
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$response = curl_exec($ch);
		$geoloc = is_string($response) ? json_decode($response, true) : null;

		// leave if the request failed or nothing was found
		if ( ! isset($geoloc['results'][0]['geometry']['location']['lat'], $geoloc['results'][0]['geometry']['location']['lng']) ) {
			return;
		}

		foreach ( $geoloc['results'][0]['address_components'] ?? [] as $address_component ) {
			$type0 = $address_component['types'][0] ?? null;
			$type1 = $address_component['types'][1] ?? null;

			if ( $type0 === 'country' && $type1 === 'political' ) {
				$ev['country'] = $address_component['long_name'];
			}

			if ( $type0 === 'locality' && $type1 === 'political' ) {
				$ev['city'] = $address_component['long_name'];
			}

			if ( $type0 === 'postal_code' ) {
				$ev['zip'] = $address_component['long_name'];
			}
		}

		// build the coord string
		$lat = $geoloc['results'][0]['geometry']['location']['lat'];
		$lng = $geoloc['results'][0]['geometry']['location']['lng'];

		// set the header info
		$ev['coordinates'] = $lat . ", " . $lng;
		$header->event = $ev;
		$page->header($header);
	}

	/**
	 * Handle saving of the plugin settings
	 *
	 * Starts the iCalendar import when requested and resets the flag.
	 *
	 * @param  mixed $obj The object that is being saved
	 * @since  1.2.0 Grav 2.0 support (extracted from onAdminSave)
	 * @return void
	 */
	protected function handleSettingsSave($obj)
	{
		// onAdminSave also fires for users, other plugins' settings, etc.
		if ( ! ($obj instanceof \ArrayAccess) || ! isset($obj['icalendar_update']) ) {
			return;
		}

		$update_mode = (int) $obj['icalendar_update'];

		if ( $update_mode != 0 ) {
			// process iCalendar file(s)
			$icalendar = new \Events\iCalendarProcessor();
			$icalendar->process($update_mode-1);

			// reset flag
			$obj['icalendar_update'] = 0;
		}
	}

	/**
	 * Association with twig extensions
	 *
	 * @since  1.1
	 * @return void
	 */
	public function onTwigExtensions()
	{
		require_once(__DIR__ . '/twig/DateTranslationExtension.php');
		$this->grav['twig']->twig()->addExtension(new DateTranslationExtension());
	}
}
