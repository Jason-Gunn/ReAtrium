<?php
/**
 * Demo / Sample Data Seeder & Wiper for ReAtrium.
 *
 * Tags every generated post with `_reatrium_is_sample = '1'` and every generated
 * user with `reatrium_is_sample = '1'` so wiping demo data never touches real
 * company content or real employee accounts.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Sample_Data {

	public const META_KEY = '_reatrium_is_sample';
	public const USER_KEY = 'reatrium_is_sample';

	/**
	 * Return counts of currently installed sample items.
	 *
	 * @return array{users:int, announcements:int, kb:int, requests:int, total:int}
	 */
	public static function stats(): array {
		$users = get_users( [
			'meta_key'   => self::USER_KEY,
			'meta_value' => '1',
			'fields'     => 'ID',
		] );

		$counts = [
			'users'         => count( $users ),
			'announcements' => self::count_sample_posts( 'reatrium_announce' ),
			'kb'            => self::count_sample_posts( 'reatrium_kb' ),
			'requests'      => self::count_sample_posts( 'reatrium_request' ),
		];
		$counts['total'] = array_sum( $counts );

		return $counts;
	}

	private static function count_sample_posts( string $post_type ): int {
		$ids = get_posts( [
			'post_type'               => $post_type,
			'post_status'             => 'any',
			'posts_per_page'          => -1,
			'fields'                  => 'ids',
			'meta_key'                => self::META_KEY,
			'meta_value'              => '1',
			'reatrium_internal_query' => true,
		] );
		return count( $ids );
	}

	/**
	 * Seed realistic SMB demo users, announcements, handbook SOPs, and helpdesk requests.
	 * Idempotent: skips items that already exist.
	 *
	 * @return array{users:int, announcements:int, kb:int, requests:int}
	 */
	public static function seed(): array {
		// Ensure KB taxonomy exists even if KB was just enabled on the same request.
		if ( ! taxonomy_exists( 'reatrium_department' ) ) {
			( new ReAtrium_Module_KB() )->register_cpt_and_taxonomy();
		}

		$created = [
			'users'         => 0,
			'announcements' => 0,
			'kb'            => 0,
			'requests'      => 0,
		];

		// 1. Demo Team Members.
		$demo_users = [
			[
				'login'    => 'demo_sarah_chen',
				'email'    => 'sarah.chen@example.com',
				'first'    => 'Sarah',
				'last'     => 'Chen',
				'role'     => 'editor',
				'title'    => 'Operations Director',
				'dept'     => 'Operations',
				'location' => 'Austin, TX (Hybrid)',
				'skills'   => 'Vendor Onboarding, Facilities, Quarterly Planning, SOPs, Procurement',
			],
			[
				'login'    => 'demo_marcus_vance',
				'email'    => 'marcus.vance@example.com',
				'first'    => 'Marcus',
				'last'     => 'Vance',
				'role'     => 'editor',
				'title'    => 'IT & Security Lead',
				'dept'     => 'IT',
				'location' => 'Remote (US-Central)',
				'skills'   => 'VPN, Okta SSO, Mac/Windows MDM, AWS Billing, Security Incidents, WordPress',
			],
			[
				'login'    => 'demo_elena_rostova',
				'email'    => 'elena.rostova@example.com',
				'first'    => 'Elena',
				'last'     => 'Rostova',
				'role'     => 'editor',
				'title'    => 'People & HR Manager',
				'dept'     => 'HR',
				'location' => 'Chicago, IL',
				'skills'   => 'PTO Policy, Benefits, Payroll, Onboarding, Performance Reviews, Compliance',
			],
			[
				'login'    => 'demo_devon_brooks',
				'email'    => 'devon.brooks@example.com',
				'first'    => 'Devon',
				'last'     => 'Brooks',
				'role'     => 'author',
				'title'    => 'Senior Account Executive',
				'dept'     => 'Sales',
				'location' => 'Denver, CO',
				'skills'   => 'Enterprise Demos, CRM, Pricing Approvals, Proposals, Partner Channels',
			],
			[
				'login'    => 'demo_priya_patel',
				'email'    => 'priya.patel@example.com',
				'first'    => 'Priya',
				'last'     => 'Patel',
				'role'     => 'author',
				'title'    => 'Product & Marketing Lead',
				'dept'     => 'General',
				'location' => 'Remote (US-East)',
				'skills'   => 'Brand Guidelines, Blog Content, Customer FAQs, Product Launches, Copywriting',
			],
			[
				'login'    => 'demo_liam_oconnor',
				'email'    => 'liam.oconnor@example.com',
				'first'    => 'Liam',
				'last'     => "O'Connor",
				'role'     => 'contributor',
				'title'    => 'IT Support Specialist',
				'dept'     => 'IT',
				'location' => 'Austin, TX',
				'skills'   => 'Hardware Requests, Slack Admin, Software Licenses, Laptop Setup, Troubleshooting',
			],
		];

		$user_ids = [];
		foreach ( $demo_users as $u ) {
			$existing = get_user_by( 'login', $u['login'] );
			if ( $existing ) {
				$user_ids[ $u['login'] ] = (int) $existing->ID;
				continue;
			}

			$uid = wp_insert_user( [
				'user_login'   => $u['login'],
				'user_email'   => $u['email'],
				'user_pass'    => wp_generate_password( 20, true, true ),
				'first_name'   => $u['first'],
				'last_name'    => $u['last'],
				'display_name' => $u['first'] . ' ' . $u['last'],
				'role'         => $u['role'],
			] );

			if ( ! is_wp_error( $uid ) && $uid > 0 ) {
				update_user_meta( $uid, 'reatrium_job_title', $u['title'] );
				update_user_meta( $uid, 'reatrium_department', $u['dept'] );
				update_user_meta( $uid, 'reatrium_skills', $u['skills'] );
				update_user_meta( $uid, 'reatrium_location', $u['location'] );
				update_user_meta( $uid, self::USER_KEY, '1' );
				$user_ids[ $u['login'] ] = (int) $uid;
				$created['users']++;
			}
		}

		$fallback_author = get_current_user_id() ?: 1;
		$author_for      = static fn( string $login ): int => $user_ids[ $login ] ?? $fallback_author;

		// 2. Demo Company Announcements.
		$announcements = [
			[
				'title'   => 'Welcome to ReAtrium — Our New Company Hub!',
				'content' => "Our new internal workspace is live. Use **Ask ReAtrium** to search across our handbook and team directory, browse SOPs by department, or submit IT/HR/Operations requests directly from the portal.\n\nPlease take 2 minutes to update your skills and location in the People Directory so colleagues know what you own.",
				'dept'    => 'All Company',
				'pinned'  => '1',
				'author'  => $author_for( 'demo_sarah_chen' ),
				'days'    => 1,
			],
			[
				'title'   => 'Action Required: VPN & SSO Security Upgrade by Friday',
				'content' => "IT is migrating all team laptops to Okta SSO + WireGuard VPN this Friday at 5:00 PM CT.\n\nPlease follow the **VPN, Wi-Fi & Hardware Setup Guide** in the Handbook before Friday afternoon. If you run into any issues, submit a High-priority IT request in the Requests tab.",
				'dept'    => 'IT',
				'pinned'  => '1',
				'author'  => $author_for( 'demo_marcus_vance' ),
				'days'    => 2,
			],
			[
				'title'   => 'Updated Holiday Calendar & Floating PTO Days',
				'content' => "We have added two floating cultural/personal holidays per calendar year on top of our standard 15 days of PTO. Floating days do not roll over and can be scheduled with your manager with 1 week of notice.",
				'dept'    => 'HR',
				'pinned'  => '0',
				'author'  => $author_for( 'demo_elena_rostova' ),
				'days'    => 4,
			],
			[
				'title'   => 'Q4 All-Hands Deck & Customer Growth Milestone',
				'content' => "Thanks to everyone's hard work, we closed Q3 at 118% of our revenue target with 97% customer retention! The Q4 All-Hands is scheduled for next Tuesday at 10:00 AM CT. Submit questions ahead of time to Operations.",
				'dept'    => 'Operations',
				'pinned'  => '0',
				'author'  => $author_for( 'demo_sarah_chen' ),
				'days'    => 6,
			],
			[
				'title'   => 'New AI Governance Guidelines for Public Content',
				'content' => "When drafting public blog posts or customer FAQs, always use the **Public AI Drafter** in WordPress Admin. Never paste internal customer records, financial spreadsheets, or HR data into public-facing AI tools.",
				'dept'    => 'General',
				'pinned'  => '0',
				'author'  => $author_for( 'demo_priya_patel' ),
				'days'    => 9,
			],
		];

		foreach ( $announcements as $item ) {
			if ( self::sample_post_exists( 'reatrium_announce', $item['title'] ) ) {
				continue;
			}
			$date    = gmdate( 'Y-m-d H:i:s', time() - ( $item['days'] * DAY_IN_SECONDS ) );
			$post_id = wp_insert_post( [
				'post_type'     => 'reatrium_announce',
				'post_status'   => 'publish',
				'post_title'    => $item['title'],
				'post_content'  => $item['content'],
				'post_author'   => $item['author'],
				'post_date_gmt' => $date,
				'post_date'     => get_date_from_gmt( $date ),
			] );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_reatrium_pinned', $item['pinned'] );
				update_post_meta( $post_id, '_reatrium_announce_dept', $item['dept'] );
				update_post_meta( $post_id, self::META_KEY, '1' );
				$created['announcements']++;
			}
		}

		// 3. Demo Handbook Articles / SOPs.
		$kb_articles = [
			[
				'title'   => 'PTO, Sick Leave & Company Holidays Policy',
				'dept'    => 'HR',
				'author'  => $author_for( 'demo_elena_rostova' ),
				'content' => "Purpose: Outline paid time off, sick leave, and holiday benefits for full-time employees.\n\nAnnual PTO: Full-time employees accrue 15 days (120 hours) of Paid Time Off per calendar year, plus 2 floating personal holidays and 10 company-observed holidays.\n\nCarryover: Up to 5 unused PTO days (40 hours) may carry over into the next calendar year. Floating holidays do not carry over.\n\nHow to Request: Submit PTO requests at least 2 weeks in advance for trips longer than 2 days. Sick leave (up to 7 paid days/year) requires notifying your manager as early as possible.\n\nOwner: Elena Rostova (HR).",
			],
			[
				'title'   => 'VPN, Wi-Fi & Hardware Setup Guide',
				'dept'    => 'IT',
				'author'  => $author_for( 'demo_marcus_vance' ),
				'content' => "Purpose: Standard steps to connect company laptops to WireGuard VPN, office Wi-Fi, and Okta SSO.\n\n1. Install the company WireGuard profile from the MDM Self-Service app.\n2. Sign into Okta SSO using your company email and hardware/app MFA (SMS MFA is disabled).\n3. Office Wi-Fi uses SSID 'Corp-Secure' with your Okta credentials. Guests should use 'Corp-Guest'.\n4. Lost or damaged hardware (chargers, monitors, laptops) must be reported immediately via the Requests & Helpdesk tab.\n\nOwner: Marcus Vance & Liam O'Connor (IT).",
			],
			[
				'title'   => 'Expense Reimbursement & Corporate Card Policy',
				'dept'    => 'Operations',
				'author'  => $author_for( 'demo_sarah_chen' ),
				'content' => "Purpose: Define approval thresholds and receipt rules for business expenses.\n\nPre-Approval Thresholds:\n- Under \$100: No pre-approval needed for standard supplies or client coffee.\n- \$100 to \$1,000: Manager approval required.\n- Over \$1,000: Operations Director (Sarah Chen) approval required.\n\nSoftware Subscriptions: All recurring SaaS tools (Figma, Notion, AWS, etc.) must be requested through IT/Operations so they are billed to the company card.\n\nReceipts: Upload itemized receipts within 14 days of purchase. Reimbursements are paid out with bi-weekly payroll.\n\nOwner: Sarah Chen (Operations).",
			],
			[
				'title'   => 'New Employee 30-Day Onboarding Checklist',
				'dept'    => 'HR',
				'author'  => $author_for( 'demo_elena_rostova' ),
				'content' => "Day 1:\n- Confirm laptop, Okta SSO, email, and Slack access with IT.\n- Complete payroll, tax forms, and benefits enrollment with HR.\n- Update your ReAtrium People Directory profile with your role and skills.\n\nWeek 1:\n- Read core Handbook policies (PTO, Security, Expenses).\n- Schedule 15-minute intro coffees with 3 teammates across other departments.\n\nDay 30:\n- Complete 30-day check-in with your manager and share feedback on the onboarding process.\n\nOwner: Elena Rostova (HR).",
			],
			[
				'title'   => 'Security Incident & Phishing Reporting SOP',
				'dept'    => 'IT',
				'author'  => $author_for( 'demo_marcus_vance' ),
				'content' => "Purpose: Rapid response steps if you receive a suspicious email, click a phishing link, or lose a device.\n\n1. Do NOT forward suspicious attachments or reply to the sender.\n2. Report immediately by submitting a High-priority IT Request in ReAtrium or messaging Marcus Vance directly.\n3. If you entered credentials on a suspicious site, reset your Okta password immediately and revoke active sessions.\n4. Never paste customer PII, passwords, or API keys into unapproved external AI tools.\n\nOwner: Marcus Vance (IT & Security).",
			],
			[
				'title'   => 'Sales Discount Approval & Deal Desk Process',
				'dept'    => 'Sales',
				'author'  => $author_for( 'demo_devon_brooks' ),
				'content' => "Purpose: Streamline pricing approvals for annual and multi-year customer contracts.\n\nStandard Discounts:\n- Up to 10% for annual upfront payment: Account Executive self-approved.\n- 11% to 20%: Requires Sales Lead sign-off.\n- Over 20% or custom legal terms: Requires Operations + Leadership review (24-hour SLA).\n\nOwner: Devon Brooks (Sales).",
			],
		];

		$kb_module = new ReAtrium_Module_KB();
		foreach ( $kb_articles as $doc ) {
			if ( self::sample_post_exists( 'reatrium_kb', $doc['title'] ) ) {
				continue;
			}
			$post_id = wp_insert_post( [
				'post_type'    => 'reatrium_kb',
				'post_status'  => 'publish',
				'post_title'   => $doc['title'],
				'post_content' => $doc['content'],
				'post_author'  => $doc['author'],
			] );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				wp_set_object_terms( $post_id, $doc['dept'], 'reatrium_department' );
				update_post_meta( $post_id, self::META_KEY, '1' );
				$post_obj = get_post( $post_id );
				if ( $post_obj instanceof WP_Post ) {
					$kb_module->index_kb_post( $post_id, $post_obj );
				}
				$created['kb']++;
			}
		}

		// 4. Demo Internal Helpdesk Requests.
		$requests = [
			[
				'title'    => 'Need Figma seat for Q4 landing page refresh',
				'content'  => 'Hi IT, can we add a Figma Professional editor seat to my account before Wednesday? Working on the Q4 product launch mockups with Priya.',
				'dept'     => 'IT',
				'status'   => 'open',
				'priority' => 'medium',
				'triage'   => "1) Suggested Department: IT\n2) Suggested Priority: Medium\n3) Draft Response: Hi Priya/Devon — I've queued your Figma license request under our company card and will assign the seat within 24 hours.",
				'reply'    => '',
				'author'   => $author_for( 'demo_priya_patel' ),
			],
			[
				'title'    => 'Laptop USB-C charger stopped working',
				'content'  => 'My MacBook Pro 96W USB-C power adapter stopped charging this morning. Currently at 35% battery and working from Austin office.',
				'dept'     => 'IT',
				'status'   => 'in_progress',
				'priority' => 'high',
				'triage'   => "1) Suggested Department: IT\n2) Suggested Priority: High\n3) Draft Response: We have spare 96W USB-C chargers in the Austin supply cabinet — Liam is bringing one over now.",
				'reply'    => 'Spare charger handed off at desk; replacement ordered from Apple Business.',
				'author'   => $author_for( 'demo_devon_brooks' ),
			],
			[
				'title'    => 'Question about carrying over unused PTO days',
				'content'  => 'I have 6 unused PTO days left this year. Can I carry all 6 into January, or is the cap 5 days?',
				'dept'     => 'HR',
				'status'   => 'resolved',
				'priority' => 'low',
				'triage'   => "1) Suggested Department: HR\n2) Suggested Priority: Low\n3) Draft Response: Per our PTO Policy, up to 5 unused days (40 hours) carry over automatically. We recommend taking the 6th day before Dec 31!",
				'reply'    => 'Confirmed! Up to 5 days carry over into January. Feel free to book that 6th day in December.',
				'author'   => $author_for( 'demo_liam_oconnor' ),
			],
			[
				'title'    => 'Add Northstar Printing to approved vendor list',
				'content'  => 'We want to order trade show banners (\$650) from Northstar Printing. Attaching W-9 once Operations opens the vendor ticket.',
				'dept'     => 'Operations',
				'status'   => 'open',
				'priority' => 'medium',
				'triage'   => "1) Suggested Department: Operations\n2) Suggested Priority: Medium\n3) Draft Response: Thanks! Since the order is between \$100 and \$1,000, please confirm manager approval and send the W-9 to Sarah Chen for vendor onboarding.",
				'reply'    => '',
				'author'   => $author_for( 'demo_devon_brooks' ),
			],
		];

		foreach ( $requests as $req ) {
			if ( self::sample_post_exists( 'reatrium_request', $req['title'] ) ) {
				continue;
			}
			$post_id = wp_insert_post( [
				'post_type'    => 'reatrium_request',
				'post_status'  => 'publish',
				'post_title'   => $req['title'],
				'post_content' => $req['content'],
				'post_author'  => $req['author'],
			] );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, '_reatrium_request_dept', $req['dept'] );
				update_post_meta( $post_id, '_reatrium_request_status', $req['status'] );
				update_post_meta( $post_id, '_reatrium_request_priority', $req['priority'] );
				update_post_meta( $post_id, '_reatrium_ai_triage', $req['triage'] );
				if ( $req['reply'] !== '' ) {
					update_post_meta( $post_id, '_reatrium_staff_reply', $req['reply'] );
				}
				update_post_meta( $post_id, self::META_KEY, '1' );
				$created['requests']++;
			}
		}

		return $created;
	}

	/**
	 * Wipe ONLY tagged sample posts and tagged sample users.
	 * Never touches real company content or the currently logged-in admin.
	 *
	 * @return array{users:int, announcements:int, kb:int, requests:int}
	 */
	public static function wipe(): array {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$deleted = [
			'users'         => 0,
			'announcements' => 0,
			'kb'            => 0,
			'requests'      => 0,
		];

		$type_map = [
			'reatrium_announce' => 'announcements',
			'reatrium_kb'       => 'kb',
			'reatrium_request'  => 'requests',
		];

		foreach ( $type_map as $cpt => $key ) {
			$ids = get_posts( [
				'post_type'               => $cpt,
				'post_status'             => 'any',
				'posts_per_page'          => -1,
				'fields'                  => 'ids',
				'meta_key'                => self::META_KEY,
				'meta_value'              => '1',
				'reatrium_internal_query' => true,
			] );
			foreach ( $ids as $post_id ) {
				if ( $cpt === 'reatrium_kb' ) {
					ReAtrium_AI_Gateway::vector_store()->delete_by_post( (int) $post_id );
				}
				if ( wp_delete_post( (int) $post_id, true ) ) {
					$deleted[ $key ]++;
				}
			}
		}

		$current_uid = get_current_user_id();
		$sample_uids = get_users( [
			'meta_key'   => self::USER_KEY,
			'meta_value' => '1',
			'fields'     => 'ID',
		] );
		foreach ( $sample_uids as $uid ) {
			$uid = (int) $uid;
			if ( $uid > 0 && $uid !== $current_uid ) {
				if ( wp_delete_user( $uid ) ) {
					$deleted['users']++;
				}
			}
		}

		return $deleted;
	}

	private static function sample_post_exists( string $post_type, string $title ): bool {
		$existing = get_posts( [
			'post_type'               => $post_type,
			'post_status'             => 'any',
			'title'                   => $title,
			'posts_per_page'          => 1,
			'fields'                  => 'ids',
			'meta_key'                => self::META_KEY,
			'meta_value'              => '1',
			'reatrium_internal_query' => true,
		] );
		return ! empty( $existing );
	}
}
