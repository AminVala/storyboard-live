<?php
/**
 * Built-in ready template catalog — 15 templates (5 free, 10 pro).
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Templates;

/**
 * Provides immutable built-in template definitions.
 *
 * Templates are data only — no WordPress imports, no remote resources,
 * no site-specific branding. Selecting a template creates a new sequence
 * draft and advances the wizard directly to Step 2.
 */
final class TemplateCatalog {

	/* ── Free template IDs ──────────────────────────────────── */
	const CREATIVE_STUDIO     = 'creative-studio-production-sheet';
	const PRODUCT_LAUNCH_HERO = 'product-launch-hero';
	const AGENCY_PORTFOLIO    = 'agency-portfolio-reveal';
	const REAL_ESTATE         = 'real-estate-showcase';
	const BRAND_STORY         = 'brand-story-intro';

	/* ── Pro template IDs ───────────────────────────────────── */
	const RESTAURANT_MENU     = 'restaurant-menu-scroll';
	const FASHION_LOOKBOOK    = 'fashion-lookbook';
	const SAAS_FEATURE_TOUR   = 'saas-feature-tour';
	const TRAVEL_DESTINATION  = 'travel-destination';
	const EVENT_CONFERENCE    = 'event-and-conference';
	const ARCHITECTURE        = 'architecture-showcase';
	const EDUCATIONAL_COURSE  = 'educational-course';
	const PHOTOGRAPHY         = 'photography-portfolio';
	const FITNESS_STUDIO      = 'fitness-studio';
	const WEDDING_GALLERY     = 'wedding-gallery';

	/** @var string[] Free template IDs */
	private static $free = array(
		self::CREATIVE_STUDIO,
		self::PRODUCT_LAUNCH_HERO,
		self::AGENCY_PORTFOLIO,
		self::REAL_ESTATE,
		self::BRAND_STORY,
	);

	/** @return array<string,array<string,mixed>> All templates, free first */
	public function all(): array {
		return array(
			self::CREATIVE_STUDIO     => $this->creative_studio(),
			self::PRODUCT_LAUNCH_HERO => $this->product_launch_hero(),
			self::AGENCY_PORTFOLIO    => $this->agency_portfolio(),
			self::REAL_ESTATE         => $this->real_estate(),
			self::BRAND_STORY         => $this->brand_story(),
			self::RESTAURANT_MENU     => $this->restaurant_menu(),
			self::FASHION_LOOKBOOK    => $this->fashion_lookbook(),
			self::SAAS_FEATURE_TOUR   => $this->saas_feature_tour(),
			self::TRAVEL_DESTINATION  => $this->travel_destination(),
			self::EVENT_CONFERENCE    => $this->event_conference(),
			self::ARCHITECTURE        => $this->architecture(),
			self::EDUCATIONAL_COURSE  => $this->educational_course(),
			self::PHOTOGRAPHY         => $this->photography(),
			self::FITNESS_STUDIO      => $this->fitness_studio(),
			self::WEDDING_GALLERY     => $this->wedding_gallery(),
		);
	}

	/** @return array<string,mixed>|null */
	public function get( string $template_id ): ?array {
		return $this->all()[ $template_id ] ?? null;
	}

	public function is_pro( string $template_id ): bool {
		return ! in_array( $template_id, self::$free, true );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/** Build a beat list from labels, frames-per-beat, and beats-per-scene. */
	private function beats( array $labels, int $fpb, int $bps ): array {
		$total  = count( $labels );
		$result = array();
		$scene  = 1;
		foreach ( $labels as $i => $label ) {
			if ( $i > 0 && $i % $bps === 0 ) {
				++$scene;
			}
			$pct_start = round( $i / $total * 100, 2 );
			$pct_end   = round( ( $i + 1 ) / $total * 100, 2 );
			$result[]  = array(
				'label'       => $label,
				'startFrame'  => $i * $fpb + 1,
				'endFrame'    => ( $i + 1 ) * $fpb,
				'scrollStart' => $pct_start,
				'scrollEnd'   => $pct_end,
				'scene'       => $scene,
			);
		}
		return $result;
	}

	// ── FREE TEMPLATES (5) ──────────────────────────────────────────────────

	private function creative_studio(): array {
		$labels = array(
			'Blank Canvas', 'First Mark', 'Sketch & Idea',
			'Detail Build', 'Color Layer', 'Geometry & Tools',
			'Full Studio',  'Story Mark',  'Narrative Focus',
			'Live Message', 'Action Path', 'Golden Handoff',
		);
		return array(
			'id'          => self::CREATIVE_STUDIO,
			'version'     => 2,
			'name'        => __( 'Creative Studio Production Sheet', 'sh-sequence-engine' ),
			'description' => __( 'A cinematic 120-frame journey through the creative process — blank canvas to golden handoff, with live HTML overlays and real theme-header reveal.', 'sh-sequence-engine' ),
			'category'    => 'cinematic',
			'category_label' => __( 'Cinematic Story', 'sh-sequence-engine' ),
			'isPro'       => false,
			'tags'        => array( 'popular' ),
			'palette'     => array( 'bg' => '#1a2035', 'accent' => '#f5a623', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 120,
				'referenceFrame' => 70,
				'goldenFrame'    => 120,
				'scenes'         => array(
					array( 'title' => 'Blank Workspace & Sketch', 'startFrame' => 1,   'endFrame' => 30  ),
					array( 'title' => 'Materials & Creation',     'startFrame' => 31,  'endFrame' => 60  ),
					array( 'title' => 'Full Studio Build',        'startFrame' => 61,  'endFrame' => 90  ),
					array( 'title' => 'Live Story & Handoff',     'startFrame' => 91,  'endFrame' => 120 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'story-mark', 'frame' => 71,  'type' => 'html' ),
					array( 'key' => 'eyebrow',    'frame' => 91,  'type' => 'html' ),
					array( 'key' => 'title',      'frame' => 94,  'type' => 'html' ),
					array( 'key' => 'subtitle',   'frame' => 101, 'type' => 'html' ),
					array( 'key' => 'cta',        'frame' => 104, 'type' => 'html' ),
					array( 'key' => 'trust',      'frame' => 107, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function product_launch_hero(): array {
		$labels = array(
			'Dark Teaser', 'Silhouette Emerge', 'Name Reveal',
			'Feature 1',   'Feature 2',         'Feature 3',
			'Price Drop',  'Social Proof',       'Buy Now',
		);
		return array(
			'id'          => self::PRODUCT_LAUNCH_HERO,
			'version'     => 1,
			'name'        => __( 'Product Launch Hero', 'sh-sequence-engine' ),
			'description' => __( '90-frame product reveal — teaser darkness to full spotlight, countdown badge, social proof, and conversion CTA.', 'sh-sequence-engine' ),
			'category'    => 'ecommerce',
			'category_label' => __( 'E-commerce', 'sh-sequence-engine' ),
			'isPro'       => false,
			'tags'        => array( 'popular', 'new' ),
			'palette'     => array( 'bg' => '#0b0f1a', 'accent' => '#ffd700', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 90,
				'referenceFrame' => 60,
				'goldenFrame'    => 90,
				'scenes'         => array(
					array( 'title' => 'Teaser & Mystery',   'startFrame' => 1,  'endFrame' => 30 ),
					array( 'title' => 'Reveal & Features',  'startFrame' => 31, 'endFrame' => 60 ),
					array( 'title' => 'Proof & Conversion', 'startFrame' => 61, 'endFrame' => 90 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'countdown',     'frame' => 1,  'type' => 'html' ),
					array( 'key' => 'product-name',  'frame' => 30, 'type' => 'html' ),
					array( 'key' => 'tagline',       'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'price',         'frame' => 61, 'type' => 'html' ),
					array( 'key' => 'social-proof',  'frame' => 70, 'type' => 'html' ),
					array( 'key' => 'buy-now',       'frame' => 80, 'type' => 'html' ),
					array( 'key' => 'trust-badges',  'frame' => 85, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function agency_portfolio(): array {
		$labels = array(
			'Black Void',   'Grid Emerge',  'Logo Mark',
			'Project 1',    'Project 2',    'Project 3',
			'Team & Ethos', 'Awards',       'Contact',
		);
		return array(
			'id'          => self::AGENCY_PORTFOLIO,
			'version'     => 1,
			'name'        => __( 'Agency Portfolio Reveal', 'sh-sequence-engine' ),
			'description' => __( '72-frame brutalist portfolio — black void to full grid, work reveal, awards, and contact with bold typographic overlays.', 'sh-sequence-engine' ),
			'category'    => 'portfolio',
			'category_label' => __( 'Portfolio / Agency', 'sh-sequence-engine' ),
			'isPro'       => false,
			'tags'        => array(),
			'palette'     => array( 'bg' => '#000000', 'accent' => '#ffffff', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 72,
				'referenceFrame' => 48,
				'goldenFrame'    => 72,
				'scenes'         => array(
					array( 'title' => 'Intro & Identity', 'startFrame' => 1,  'endFrame' => 24 ),
					array( 'title' => 'Selected Work',    'startFrame' => 25, 'endFrame' => 48 ),
					array( 'title' => 'Agency & CTA',     'startFrame' => 49, 'endFrame' => 72 ),
				),
				'beats'          => $this->beats( $labels, 8, 3 ),
				'overlays'       => array(
					array( 'key' => 'studio-name', 'frame' => 20, 'type' => 'html' ),
					array( 'key' => 'tagline',     'frame' => 24, 'type' => 'html' ),
					array( 'key' => 'services',    'frame' => 48, 'type' => 'html' ),
					array( 'key' => 'cta',         'frame' => 65, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function real_estate(): array {
		$labels = array(
			'Dawn Exterior',  'Front Approach', 'Façade Detail',
			'Entrance Hall',  'Living Space',   'Kitchen',
			'Master Suite',   'Garden View',    'Aerial',
			'Floor Plan',     'Contact & Price',
		);
		return array(
			'id'          => self::REAL_ESTATE,
			'version'     => 1,
			'name'        => __( 'Real Estate Showcase', 'sh-sequence-engine' ),
			'description' => __( '88-frame property tour — dawn exterior to floor plan, with location, price, and contact overlays optimised for luxury listings.', 'sh-sequence-engine' ),
			'category'    => 'realestate',
			'category_label' => __( 'Real Estate', 'sh-sequence-engine' ),
			'isPro'       => false,
			'tags'        => array( 'new' ),
			'palette'     => array( 'bg' => '#c8a882', 'accent' => '#3d2b1f', 'text' => '#1a1008' ),
			'structure'   => array(
				'totalFrames'    => 88,
				'referenceFrame' => 55,
				'goldenFrame'    => 88,
				'scenes'         => array(
					array( 'title' => 'Exterior & Curb',  'startFrame' => 1,  'endFrame' => 33 ),
					array( 'title' => 'Interior Journey', 'startFrame' => 34, 'endFrame' => 66 ),
					array( 'title' => 'Deal & Contact',   'startFrame' => 67, 'endFrame' => 88 ),
				),
				'beats'          => $this->beats( $labels, 8, 4 ),
				'overlays'       => array(
					array( 'key' => 'property-title', 'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'location',       'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'features',       'frame' => 50, 'type' => 'html' ),
					array( 'key' => 'price',          'frame' => 70, 'type' => 'html' ),
					array( 'key' => 'contact',        'frame' => 80, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function brand_story(): array {
		$labels = array(
			'Origin Darkness', 'Founding Moment', 'Mission Statement',
			'Brand Values',    'Audience Portrait', 'Vision Horizon',
			'Call to Join',    'Brand CTA',
		);
		return array(
			'id'          => self::BRAND_STORY,
			'version'     => 1,
			'name'        => __( 'Brand Story Intro', 'sh-sequence-engine' ),
			'description' => __( '64-frame emotional brand narrative — from founding darkness to clear vision, with values, audience portrait, and community CTA.', 'sh-sequence-engine' ),
			'category'    => 'brand',
			'category_label' => __( 'Brand / Marketing', 'sh-sequence-engine' ),
			'isPro'       => false,
			'tags'        => array(),
			'palette'     => array( 'bg' => '#c0633b', 'accent' => '#f5ede0', 'text' => '#1a0c05' ),
			'structure'   => array(
				'totalFrames'    => 64,
				'referenceFrame' => 40,
				'goldenFrame'    => 64,
				'scenes'         => array(
					array( 'title' => 'Origin & Mission', 'startFrame' => 1,  'endFrame' => 32 ),
					array( 'title' => 'Vision & CTA',     'startFrame' => 33, 'endFrame' => 64 ),
				),
				'beats'          => $this->beats( $labels, 8, 4 ),
				'overlays'       => array(
					array( 'key' => 'brand-mark',  'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'story-text',  'frame' => 20, 'type' => 'html' ),
					array( 'key' => 'values',      'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'vision',      'frame' => 50, 'type' => 'html' ),
					array( 'key' => 'cta',         'frame' => 60, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	// ── PRO TEMPLATES (10) ──────────────────────────────────────────────────

	private function restaurant_menu(): array {
		$labels = array(
			'Night Ambiance', 'Table Setting', 'Signature Dish',
			'Wine Pairing',   'Chef Moment',   'Dessert',
			'Reservation CTA',
		);
		return array(
			'id'          => self::RESTAURANT_MENU,
			'version'     => 1,
			'name'        => __( 'Restaurant Menu Scroll', 'sh-sequence-engine' ),
			'description' => __( '84-frame dining experience — candlelit ambiance to signature dishes, chef story, and table reservation CTA.', 'sh-sequence-engine' ),
			'category'    => 'food',
			'category_label' => __( 'Food & Beverage', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'popular' ),
			'palette'     => array( 'bg' => '#3b0a15', 'accent' => '#c9a84c', 'text' => '#f5e6c8' ),
			'structure'   => array(
				'totalFrames'    => 84,
				'referenceFrame' => 55,
				'goldenFrame'    => 84,
				'scenes'         => array(
					array( 'title' => 'Ambiance & Arrival',   'startFrame' => 1,  'endFrame' => 28 ),
					array( 'title' => 'Menu Hero',            'startFrame' => 29, 'endFrame' => 56 ),
					array( 'title' => 'Reservation',          'startFrame' => 57, 'endFrame' => 84 ),
				),
				'beats'          => $this->beats( $labels, 12, 2 ),
				'overlays'       => array(
					array( 'key' => 'restaurant-name', 'frame' => 12, 'type' => 'html' ),
					array( 'key' => 'dish-name',       'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'description',     'frame' => 40, 'type' => 'html' ),
					array( 'key' => 'price',           'frame' => 45, 'type' => 'html' ),
					array( 'key' => 'reserve-cta',     'frame' => 70, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function fashion_lookbook(): array {
		$labels = array(
			'Season Open',   'Look 1 — Entry',  'Look 1 — Detail',
			'Look 2 — Entry', 'Look 2 — Detail', 'Fabric Close',
			'Manifesto',     'Shop CTA',
		);
		return array(
			'id'          => self::FASHION_LOOKBOOK,
			'version'     => 1,
			'name'        => __( 'Fashion Lookbook', 'sh-sequence-engine' ),
			'description' => __( '96-frame editorial lookbook — season opening to curated looks, fabric detail, brand manifesto, and shop CTA.', 'sh-sequence-engine' ),
			'category'    => 'fashion',
			'category_label' => __( 'Fashion / Lifestyle', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'new' ),
			'palette'     => array( 'bg' => '#e8d5c4', 'accent' => '#2a2a2a', 'text' => '#1a1a1a' ),
			'structure'   => array(
				'totalFrames'    => 96,
				'referenceFrame' => 64,
				'goldenFrame'    => 96,
				'scenes'         => array(
					array( 'title' => 'Season Opening', 'startFrame' => 1,  'endFrame' => 24 ),
					array( 'title' => 'Look 1',         'startFrame' => 25, 'endFrame' => 48 ),
					array( 'title' => 'Look 2',         'startFrame' => 49, 'endFrame' => 72 ),
					array( 'title' => 'Manifesto & Buy','startFrame' => 73, 'endFrame' => 96 ),
				),
				'beats'          => $this->beats( $labels, 12, 2 ),
				'overlays'       => array(
					array( 'key' => 'season-title',   'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'look-number',    'frame' => 30, 'type' => 'html' ),
					array( 'key' => 'look-detail',    'frame' => 36, 'type' => 'html' ),
					array( 'key' => 'manifesto',      'frame' => 80, 'type' => 'html' ),
					array( 'key' => 'shop-cta',       'frame' => 90, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function saas_feature_tour(): array {
		$labels = array(
			'Problem Scene',  'Pain Point 1',   'Pain Point 2',
			'Solution Enter', 'Feature 1',      'Feature 2',
			'Feature 3',      'Dashboard View', 'Pricing',
			'Social Proof',   'Start Free CTA',
		);
		return array(
			'id'          => self::SAAS_FEATURE_TOUR,
			'version'     => 1,
			'name'        => __( 'SaaS Feature Tour', 'sh-sequence-engine' ),
			'description' => __( '110-frame product tour — problem to solution, 3 hero features, dashboard reveal, pricing, and sign-up CTA.', 'sh-sequence-engine' ),
			'category'    => 'tech',
			'category_label' => __( 'SaaS / Tech', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'popular' ),
			'palette'     => array( 'bg' => '#0d1b4b', 'accent' => '#4f8ef7', 'text' => '#e8f0fe' ),
			'structure'   => array(
				'totalFrames'    => 110,
				'referenceFrame' => 70,
				'goldenFrame'    => 110,
				'scenes'         => array(
					array( 'title' => 'Problem & Pain',      'startFrame' => 1,  'endFrame' => 33 ),
					array( 'title' => 'Solution & Features', 'startFrame' => 34, 'endFrame' => 77 ),
					array( 'title' => 'Proof & Conversion',  'startFrame' => 78, 'endFrame' => 110 ),
				),
				'beats'          => $this->beats( $labels, 10, 4 ),
				'overlays'       => array(
					array( 'key' => 'headline',    'frame' => 5,   'type' => 'html' ),
					array( 'key' => 'problem',     'frame' => 15,  'type' => 'html' ),
					array( 'key' => 'feature-1',   'frame' => 40,  'type' => 'html' ),
					array( 'key' => 'feature-2',   'frame' => 55,  'type' => 'html' ),
					array( 'key' => 'feature-3',   'frame' => 65,  'type' => 'html' ),
					array( 'key' => 'pricing',     'frame' => 85,  'type' => 'html' ),
					array( 'key' => 'proof',       'frame' => 95,  'type' => 'html' ),
					array( 'key' => 'cta',         'frame' => 105, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function travel_destination(): array {
		$labels = array(
			'Horizon Dawn',   'Flight Path',   'Landing Vista',
			'City Pulse',     'Hidden Gem 1',  'Hidden Gem 2',
			'Local Culture',  'Cuisine',       'Adventure',
			'Sunset Memory',  'Book Trip CTA',
		);
		return array(
			'id'          => self::TRAVEL_DESTINATION,
			'version'     => 1,
			'name'        => __( 'Travel Destination', 'sh-sequence-engine' ),
			'description' => __( '110-frame destination story — dawn horizon to hidden gems, culture, cuisine, adventure, and booking CTA.', 'sh-sequence-engine' ),
			'category'    => 'travel',
			'category_label' => __( 'Travel', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'new' ),
			'palette'     => array( 'bg' => '#0e4166', 'accent' => '#00ccd4', 'text' => '#e0f7fa' ),
			'structure'   => array(
				'totalFrames'    => 110,
				'referenceFrame' => 66,
				'goldenFrame'    => 110,
				'scenes'         => array(
					array( 'title' => 'Arrival & Vista',    'startFrame' => 1,  'endFrame' => 33 ),
					array( 'title' => 'Discover & Explore', 'startFrame' => 34, 'endFrame' => 66 ),
					array( 'title' => 'Culture & Taste',    'startFrame' => 67, 'endFrame' => 88 ),
					array( 'title' => 'Memory & Book',      'startFrame' => 89, 'endFrame' => 110 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'destination-name', 'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'tagline',          'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'highlight',        'frame' => 55, 'type' => 'html' ),
					array( 'key' => 'culture-text',     'frame' => 70, 'type' => 'html' ),
					array( 'key' => 'book-cta',         'frame' => 100, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function event_conference(): array {
		$labels = array(
			'Countdown Open', 'Stage Build',   'Keynote Reveal',
			'Speaker 1',      'Speaker 2',     'Agenda',
			'Venue',          'Register CTA',
		);
		return array(
			'id'          => self::EVENT_CONFERENCE,
			'version'     => 1,
			'name'        => __( 'Event & Conference', 'sh-sequence-engine' ),
			'description' => __( '80-frame event hype reel — countdown and stage reveal, keynote speakers, agenda, venue, and registration CTA.', 'sh-sequence-engine' ),
			'category'    => 'events',
			'category_label' => __( 'Events', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array(),
			'palette'     => array( 'bg' => '#1a0538', 'accent' => '#f0e040', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 80,
				'referenceFrame' => 50,
				'goldenFrame'    => 80,
				'scenes'         => array(
					array( 'title' => 'Hype & Stage',    'startFrame' => 1,  'endFrame' => 40 ),
					array( 'title' => 'Register & Go',   'startFrame' => 41, 'endFrame' => 80 ),
				),
				'beats'          => $this->beats( $labels, 10, 4 ),
				'overlays'       => array(
					array( 'key' => 'event-title',  'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'date-venue',   'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'speaker-1',    'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'speaker-2',    'frame' => 45, 'type' => 'html' ),
					array( 'key' => 'register-cta', 'frame' => 70, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function architecture(): array {
		$labels = array(
			'Site & Sky',    'Foundation',   'Frame Rising',
			'Facade Detail', 'Interior Open','Space Flow',
			'Material Study','Night Mode',   'Handover',
		);
		return array(
			'id'          => self::ARCHITECTURE,
			'version'     => 1,
			'name'        => __( 'Architecture Showcase', 'sh-sequence-engine' ),
			'description' => __( '90-frame architectural reveal — bare site to finished interior, with material studies, night mode, and project handover.', 'sh-sequence-engine' ),
			'category'    => 'architecture',
			'category_label' => __( 'Architecture', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array(),
			'palette'     => array( 'bg' => '#4a4a4a', 'accent' => '#b87333', 'text' => '#f0ede8' ),
			'structure'   => array(
				'totalFrames'    => 90,
				'referenceFrame' => 60,
				'goldenFrame'    => 90,
				'scenes'         => array(
					array( 'title' => 'Site & Structure', 'startFrame' => 1,  'endFrame' => 30 ),
					array( 'title' => 'Interior & Space', 'startFrame' => 31, 'endFrame' => 60 ),
					array( 'title' => 'Finish & Handover','startFrame' => 61, 'endFrame' => 90 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'project-name',  'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'architects',    'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'material-label','frame' => 65, 'type' => 'html' ),
					array( 'key' => 'area-specs',    'frame' => 75, 'type' => 'html' ),
					array( 'key' => 'contact',       'frame' => 85, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function educational_course(): array {
		$labels = array(
			'Hook Question',  'Pain Point',    'Expert Intro',
			'Module 1',       'Module 2',      'Module 3',
			'Outcome Visual', 'Student Proof', 'Enroll CTA',
		);
		return array(
			'id'          => self::EDUCATIONAL_COURSE,
			'version'     => 1,
			'name'        => __( 'Educational Course', 'sh-sequence-engine' ),
			'description' => __( '90-frame course landing — compelling hook, expert credibility, 3 modules, outcome visuals, student testimonials, and enroll CTA.', 'sh-sequence-engine' ),
			'category'    => 'education',
			'category_label' => __( 'Education', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'popular' ),
			'palette'     => array( 'bg' => '#1b3a2d', 'accent' => '#7dca8b', 'text' => '#e8f5ea' ),
			'structure'   => array(
				'totalFrames'    => 90,
				'referenceFrame' => 55,
				'goldenFrame'    => 90,
				'scenes'         => array(
					array( 'title' => 'Hook & Credibility', 'startFrame' => 1,  'endFrame' => 30 ),
					array( 'title' => 'Curriculum',          'startFrame' => 31, 'endFrame' => 60 ),
					array( 'title' => 'Proof & Enroll',      'startFrame' => 61, 'endFrame' => 90 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'hook-text',    'frame' => 5,  'type' => 'html' ),
					array( 'key' => 'expert-name',  'frame' => 20, 'type' => 'html' ),
					array( 'key' => 'module-title', 'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'outcome',      'frame' => 65, 'type' => 'html' ),
					array( 'key' => 'testimonial',  'frame' => 75, 'type' => 'html' ),
					array( 'key' => 'enroll-cta',   'frame' => 85, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function photography(): array {
		$labels = array(
			'Black Frame',   'Lens Iris',     'Portfolio 1',
			'Portfolio 2',   'Portfolio 3',   'Behind Scene',
			'Style & Edit',  'Book a Shoot',
		);
		return array(
			'id'          => self::PHOTOGRAPHY,
			'version'     => 1,
			'name'        => __( 'Photography Portfolio', 'sh-sequence-engine' ),
			'description' => __( '80-frame photographer showcase — aperture open to curated gallery, behind-the-scenes, editing style, and booking CTA.', 'sh-sequence-engine' ),
			'category'    => 'photography',
			'category_label' => __( 'Photography', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array(),
			'palette'     => array( 'bg' => '#050505', 'accent' => '#e0e0e0', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 80,
				'referenceFrame' => 50,
				'goldenFrame'    => 80,
				'scenes'         => array(
					array( 'title' => 'Intro & Identity', 'startFrame' => 1,  'endFrame' => 30 ),
					array( 'title' => 'Gallery & Style',  'startFrame' => 31, 'endFrame' => 60 ),
					array( 'title' => 'Book & Contact',   'startFrame' => 61, 'endFrame' => 80 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'photographer-name', 'frame' => 15, 'type' => 'html' ),
					array( 'key' => 'specialty',         'frame' => 20, 'type' => 'html' ),
					array( 'key' => 'photo-caption',     'frame' => 40, 'type' => 'html' ),
					array( 'key' => 'edit-style',        'frame' => 60, 'type' => 'html' ),
					array( 'key' => 'book-cta',          'frame' => 72, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function fitness_studio(): array {
		$labels = array(
			'Dark Power Up',  'Warmup Action', 'Strength Peak',
			'Cardio Burst',   'Recovery',      'Transformation',
			'Join Class CTA',
		);
		return array(
			'id'          => self::FITNESS_STUDIO,
			'version'     => 1,
			'name'        => __( 'Fitness Studio', 'sh-sequence-engine' ),
			'description' => __( '70-frame high-energy sequence — power-up darkness to transformation reveal, class schedule, and membership CTA.', 'sh-sequence-engine' ),
			'category'    => 'fitness',
			'category_label' => __( 'Health / Wellness', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'new' ),
			'palette'     => array( 'bg' => '#0f0f0f', 'accent' => '#ff4500', 'text' => '#ffffff' ),
			'structure'   => array(
				'totalFrames'    => 70,
				'referenceFrame' => 45,
				'goldenFrame'    => 70,
				'scenes'         => array(
					array( 'title' => 'Power & Performance', 'startFrame' => 1,  'endFrame' => 35 ),
					array( 'title' => 'Result & Join',       'startFrame' => 36, 'endFrame' => 70 ),
				),
				'beats'          => $this->beats( $labels, 10, 4 ),
				'overlays'       => array(
					array( 'key' => 'studio-name',   'frame' => 5,  'type' => 'html' ),
					array( 'key' => 'energy-phrase', 'frame' => 20, 'type' => 'html' ),
					array( 'key' => 'transformation','frame' => 55, 'type' => 'html' ),
					array( 'key' => 'schedule',      'frame' => 60, 'type' => 'html' ),
					array( 'key' => 'join-cta',      'frame' => 65, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}

	private function wedding_gallery(): array {
		$labels = array(
			'Anticipation',   'Getting Ready', 'Ceremony Arch',
			'Exchange Vows',  'First Kiss',    'Reception Enter',
			'Dance Floor',    'Cake & Toast',  'Send Off',
			'Forever Frame',
		);
		return array(
			'id'          => self::WEDDING_GALLERY,
			'version'     => 1,
			'name'        => __( 'Wedding Gallery', 'sh-sequence-engine' ),
			'description' => __( '100-frame wedding storybook — anticipation to forever frame, 4 acts, floral arches, and timeless typography overlays.', 'sh-sequence-engine' ),
			'category'    => 'lifestyle',
			'category_label' => __( 'Lifestyle / Events', 'sh-sequence-engine' ),
			'isPro'       => true,
			'tags'        => array( 'popular' ),
			'palette'     => array( 'bg' => '#f9f3ee', 'accent' => '#c9748a', 'text' => '#3a2a2a' ),
			'structure'   => array(
				'totalFrames'    => 100,
				'referenceFrame' => 65,
				'goldenFrame'    => 100,
				'scenes'         => array(
					array( 'title' => 'Anticipation',  'startFrame' => 1,  'endFrame' => 25  ),
					array( 'title' => 'Ceremony',      'startFrame' => 26, 'endFrame' => 50  ),
					array( 'title' => 'Reception',     'startFrame' => 51, 'endFrame' => 75  ),
					array( 'title' => 'Forever',       'startFrame' => 76, 'endFrame' => 100 ),
				),
				'beats'          => $this->beats( $labels, 10, 3 ),
				'overlays'       => array(
					array( 'key' => 'couple-names',  'frame' => 5,  'type' => 'html' ),
					array( 'key' => 'date',          'frame' => 10, 'type' => 'html' ),
					array( 'key' => 'ceremony-text', 'frame' => 35, 'type' => 'html' ),
					array( 'key' => 'love-quote',    'frame' => 65, 'type' => 'html' ),
					array( 'key' => 'gallery-link',  'frame' => 90, 'type' => 'html' ),
				),
				'animationType'  => 'scroll-driven',
				'frameFormat'    => 'webp',
			),
		);
	}
}
