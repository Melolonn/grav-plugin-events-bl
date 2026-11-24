<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * DateTranslationExtension
 *
 * DateTranslationExtension adds a filter and a function `dateTranslate` to
 * twig templating. The extension is based on the already existing `date`
 * function and filter`.
 *
 * In twig `dateTranslate` is used similar to `date` function and filter,
 * only the result differs in that textual months and days in a date are
 * translated using the *translate array function* on `GRAV.DAYS_OF_THE_WEEK`
 * and GRAV.MONTHS_OF_THE_YEAR:
 *
 * ```
 * Filter  : {{ "2020-01-01"| dateTranslate("l, F j, Y")}}
 * Function: {{ dateTranslate("2020-01-01", "l, F j, Y")}}
 * ```
 * for English results in
 * ```
 * Filter  : Wednesday, January 01, 2020
 * Function: Wednesday, January 01, 2020
 * ```
 * which for e.g. German results in
 * ```
 * Filter  : Mittwoch, Januar 01, 2020
 * Function: Mittwoch, Januar 01, 2020
 * ```
 *
 * PHP version 8.3+ (Grav 2.0 / Twig 3)
 *
 * @since      1.1.0
 * @since      1.2.0 Twig 3 support
 */
class DateTranslationExtension extends AbstractExtension
{
    protected $day_char;
    protected $mon_char;

    public function __construct()
    {
        $grav = Grav::instance();

        // a length of 0 means "show the full name", which mb_substr() expects as null
        $day_char = (int) $grav['config']->get("plugins.events.calendar.day_char");
        $mon_char = (int) $grav['config']->get("plugins.events.calendar.month_char");

        $this->day_char = $day_char > 0 ? $day_char : null;
        $this->mon_char = $mon_char > 0 ? $mon_char : null;
    }

	/**
	 * Returns the Functions that will be exposed to twig templating
	 */
	public function getFunctions()
	{
		return [
			new TwigFunction(
				'dateTranslate',
				[$this, 'dateTranslate'],
				['needs_environment' => true]
			)
		];
	}

	/**
	 * Returns the Filters that will be exposed to twig templating
	 */
	public function getFilters()
	{
		return [
			new TwigFilter(
				'dateTranslate',
				[$this, 'dateTranslate'],
				['needs_environment' => true]
			)
		];
	}


	/**
	 * Translates textual days and years of a date string under
	 * formating by fragmenting the given date-pattern and converting
	 * the fragments individually to segments of the date. Text parts
	 * are also translated into the target language as specified in the
	 * page's frontmatter or system settings and passed by twig.
	 *
	 * The implementation makes use of the *translate array function*
	 * `ta` with GRAV.DAYS_OF_THE_WEEK and GRAV.MONTHS_OF_THE_YEAR.
	 *
	 * @param env The twig environment as required by
	 * 	the twig date format filter
	 * @param date The date time-stamp to format
	 * @param datePattern The date format pattern
	 *
	 * @return The translated date string
	 */
	public function dateTranslate(Environment $env, $date, $datePattern)
	{
		$dateFunction = function ($format) use ($env, $date) {
			// represents twig's `|date`
			// (Twig >= 3.9 has it as a static method, older Twig 3 as a global function)
			if (method_exists(CoreExtension::class, 'dateFormatFilter')) {
				return CoreExtension::dateFormatFilter($env, $date, $format);
			}
			return \twig_date_format_filter($env, $date, $format);
		};

		$dateString = '';
		$tokens = preg_split('/([DlMF])/', preg_quote($datePattern), 0, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($tokens as $t) {
			switch ($t) {
				case 'D':
					// A textual representation of a day, two letters: Mo through Su
					$dateString .= mb_substr($this->translateDay($dateFunction), 0, $this->day_char, "UTF-8");
					break;
				case 'l':
					// A full textual representation of the day of the week: Sunday through Saturday
					$dateString .= $this->translateDay($dateFunction);
					break;
				case 'M':
					// A short textual representation of a month, three letters: Jan through Dec
					$dateString .= mb_substr($this->translateMonth($dateFunction), 0, $this->mon_char, "UTF-8");
					break;
				case 'F':
					// A full textual representation of a month, such as January or March: January through December
					$dateString .= $this->translateMonth($dateFunction);
					break;
				default:
					$dateString .= $dateFunction($t);
			}
		}
		return $dateString;
	}

	private function translateDay($dateFunction)
	{
		// php `translateArray` represents twig's `|ta`
		return Grav::instance()['language']->translateArray('GRAV.DAYS_OF_THE_WEEK', ($dateFunction('w') + 6) % 7);
	}

	private function translateMonth($dateFunction)
	{
		// php `translateArray` represents twig's `|ta`
		return Grav::instance()['language']->translateArray('GRAV.MONTHS_OF_THE_YEAR', $dateFunction('n') - 1);
	}
}
