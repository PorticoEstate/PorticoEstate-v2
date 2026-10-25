<?php

namespace App\modules\email\services;

final class EmailPreferenceSchema
{
	private array $std_prefs = [];
	private array $cust_prefs = [];

	public function fields(): array
	{


		$this->std_prefs = array();
		$i = 0;
		$this->std_prefs[$i] = array(
			'id' 		=> 'ex_account_enabled',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('enable this email account'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('THIS PREF CURRENTLY DOES NOTHING Users may have more than one email account. In the future it is anticipated that automatic actions may be performed on these accounts, such as automatic new mail checks,auto filtering, etc... Perhaps the user may want to disable an account so that these automatic actions do not occur for that account. This is one possible use. Also, an admin may want to disable accounts from time to time.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'account_name',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default,extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Account Name'),
			// note that after the comma there is NO space, this means default is no value
			'init_default'	=> 'string,',
			'values'	=> array(),
			'long_desc' => lang('This is the name that appears in the account combobox. If for leave this blank, your accounts will be given a standard name like Account[1]: Jane Doe, where Jane Doe is the name you give below as Your full name. If you want to give an account a special name you can fill this in. No matter what, this is for your use, your emails will still use Your full name as your FROM name for email messages. Note that Your full name for your email account 0 is the name you gave in the phpgroupware setup.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'fullname',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Your full name'),
			'init_default'	=> 'varEVAL,$this->userSettings["fullname"];',
			'values'	=> array(),
			'long_desc' => lang('This is the name that appears in the users FROM address. The default mail account gets this value automatically from the phpgwapi. Additional accounts are created with the phpgwapi supplied fullname you can specify a different fullname for each extra email account.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'email_sig',
			'type'		=> 'user_string',
			'widget'	=> 'textarea',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> 'empty_string_ok',
			'lang_blurb'	=> lang('email signature'),
			'init_default'	=> 'string, ',
			'values'	=> array(),
			'long_desc' => lang('This text will be appended to the bottom of the users emails for this email account. Currently, html tags are not supported. Also, forwarded email will NOT get this email sig appended to it for reasons such as it clutters the email, the forwarded part probably has its own email sig from the original author, which is really the information that matters the most.')
		);
		$lang_oldest = lang('oldest');
		$lang_newest = lang('newest');
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'default_sorting',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Default sorting order'),
			'init_default'	=> 'string,new_old',
			'values'	=> array(
				'old_new' => $lang_oldest . ' -> ' . $lang_newest,
				'new_old' => $lang_newest . ' -> ' . $lang_oldest
			),
			'long_desc' => lang('In the email index page, the page which lists all the mails in a folder, mail may be sorted by date, author, size, or subject, HOWEVER all of these need to be ordered from first to last, this options controlls what is first and last. For example, if sorting by date, the newest to oldest displays the most recent emails first, in decending order down to the oldest emails last. ')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'layout',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Message List Layout'),
			'init_default'	=> 'string,2',
			'values'	=> array(
				'1' => lang('Layout 1'),
				'2' => lang('Layout 2')
			),
			'long_desc' => lang('The email application offers 2 different layouts for the index page, that is the page that lists the emails in a folder. This page may be the page the user looks at the most and so different layouts, or looks, are offered.')
		);
		$i++;

		/*!
			@capability Adding a preference option, HOWTO example.
			@abstract this example will help you understand how the email preferences work.
			@author Angles
			@discussion email preferences, like many other phpGW preference items, do not have a 
			Database table devoted only to email preferences. If there were such a Database table, its fields 
			would describe a schema similar to the one we are about to study. Email prefs are stored in the phpGW 
			preferences table, which is shared by many apps and has the charactoristics of a dynamic data store, i.e. 
			we can add, remove, or change email preference details without having to alter the Database table itself. 
			This can make programming easier, but it also can present a chalange if your app has many preferences, 
			which may have a complicted tree-like hierarchy.
			Low level preference functiond are handled by the pgpGW api. The data itself is stored in the database as 
			a serialized array using the php functions serialize and unserialize.
			We can gain the flexibility of a rich preference data handling system by doing some work up front. The 
			work in question here is an array based schema hard coded in this function, yet similar schema definitions 
			could exist in an XML file, as the concept is very similar.
			We use an associaltive array (i,e. not a simple numbered array) to hold our schema data,
			@example This is a how-to  example for adding an email preference item, saving it to the prefs DB table, 
			retieving the pref, and using it in code. This DocSubTopic deals with adding an email preference item, 
			using pref item "icon theme" as an example. A Step-by-Step example is provided with explanation,
			(1) copy an  existing preference item to use as a template for your new preference item.
			(2) replace in the schema with your information, as such:
			*id*		The "Unique ID" of this pref item. The pref DB uses this like a UID. 
			*type*	[ exists| user_string | known_string ]
			*widget*	[ checkbox | textbox | textarea | combobox | passwordbox ]
			*accts_usage*	[ default, extra_accounts ]
			*write_props*	[ empty_string_ok | password, hidden, encrypted, empty_no_delete | no_db_defang ]
			*lang_blurb*	description displayed to the user on the preferences page.
			*init_default*	--possible tokens are--
						string		[any_string]  ex. 'string,new_old'
						set_or_not	[set|not_set]  ex.  'set_or_not,not_set'
						function		[string_will_be_eval'd] ex. "function,$this->sub_default_userid($accountid)"
						init_no_fill	we will not fill this item during initialization (ex. a password)
						varEVAL		[string_to_eval] ex. "$GLOBALS['phpgw_info']['server']['mail_server']"
			*values*		Array of values available to the user, "key" => "value" , used with combobox widgets.
			*long_desc*	Long help, detained description of the option displayed to the user as "long help".
			(3) Your are done. The preference is now part of email preferences. It will bedisplayed on the preferences 
			page, saved and read from the preferences DB, it will have the default value you specified unless the user 
			chooses otherwise. All this happens automatically, without the developer having to write any more code.
			*/

		$this->std_prefs[$i] = array(
			'id' 		=> 'icon_theme',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Icon Theme'),
			'init_default'	=> 'string,evo',
			'values'	=> array(
				'evo' => lang('Evolution Style'),
				'moz' => lang('Mozilla Modern Style'),
				'noia' => lang('Noia &#64; Carlitus Style'),
				'AquaFusion' => lang('Aqua Fusion')
			),
			'long_desc' => lang('The email application offers different icon image themes, groups of images of a similar style which are used in this email application. Currently the available themes are images based on Evolution by Ximian and the Netscape7, Mozilla browser buttons. Additional themes are anticipated and welcome.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'icon_size',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Icon Size'),
			'init_default'	=> 'string,24',
			'values'	=> array(
				'16' => lang('Small'),
				'24' => lang('Big')
			),
			'long_desc' => lang('The email application offers different icon image themes, these icons can be big or small.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'button_type',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Button Type'),
			'init_default'	=> 'string,both',
			'values'	=> array(
				'text' => lang('Text'),
				'image' => lang('Image'),
				'both' => lang('Both')
			),
			'long_desc' => lang('The email application offers different button displays, these buttons can be text, images, or both.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'show_addresses',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Show sender\'s email address with name'),
			'init_default'	=> 'string,none',
			'values'	=> array(
				'none' => lang('none'),
				'From' => lang('From'),
				'ReplyTo' => lang('ReplyTo')
			),
			'long_desc' => lang('This confusing and often misunderstood option is left over from this email apps origins as Aeromail by Mark Cushman. When viewing a list of emails in a folder, the FROM column may show you a) the senders name only, if a name was provided, b) the senders From email address, in addition to the senders name, or c) the senders reply to address if it is different from the senders from address, in addition to the senders name if it was provided. Typically users set this to none, which will show only the senders name. If no name was supplied by the sender, then the senders FROM email address will be shown, whether a seperate reply to address is provided has no effect on this, the FROM address is always used if the senders name is not provided.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'mainscreen_showmail',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default',
			'write_props'	=> '',
			'lang_blurb'	=> lang('show new messages on main screen'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('Each user has a summary page which can display a variety of information. This option will show a small list of email messages in the INBOX of the users default email account on the users summary home page.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'use_trash_folder',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Deleted messages saved to folder:'),
			//'lang_blurb'	=> lang('save Deleted messages in folder named below'),
			'lang_blurb'	=> lang('Deleted messages go to Trash'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('If checked, Deleted message will be sent to the &quot;Trash&quot; folder name which you specify in the box for &quot; Deleted messages (Trash) folder &quot;. Only works with IMAP servers, POP servers do not have folders.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'trash_folder_name',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Deleted messages folder name'),
			'lang_blurb'	=> lang('Deleted messages (Trash) folder'),
			'init_default'	=> 'string,Trash',
			'values'	=> array(),
			'long_desc' => lang('If &quot; Deleted messages go to Trash &quot; is checked, Deleted message will be sent to the folder name you type in this box. If this folder does not exist, it will be created for you automatically. Default name is &quot;Trash&quot;. This will be your &quot;Trash&quot; folder, but it does not have to actually be called &quot;Trash&quot;, you can name it anything. Only works with IMAP servers, POP servers do not have folders.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'use_sent_folder',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Sent messages saved to folder:'),
			//'lang_blurb'	=> lang('save Sent messages in folder named below'),
			'lang_blurb'	=> lang('Sent messages saved in &quot;Sent&quot; folder'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('If checked, a copy of your sent mail will be stored in the &quot;Sent&quot; folder name which you specify in the box for &quot;Sent messages folder &quot;. Only works with IMAP servers, POP servers do not have folders.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'sent_folder_name',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Sent messages folder name'),
			'lang_blurb'	=> lang('Sent messages folder'),
			'init_default'	=> 'string,Sent',
			'values'	=> array(),
			'long_desc' => lang('If &quot; Sent messages folder &quot; is checked, a copy of your sent mail will be stored in the folder name you type in this box. If this folder does not exist, it will be created for you automatically. Default name is &quot;Sent&quot;. This will be your &quot;Sent&quot; folder, but it does not have to actually be called &quot;Sent&quot;, you can name it anything. Only works with IMAP servers, POP servers do not have folders.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'use_draft_folder',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Sent messages saved to folder:'),
			//'lang_blurb'	=> lang('save Sent messages in folder named below'),
			'lang_blurb'	=> htmlentities(lang('draft messages saved in "draft" folder')),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc'	=> htmlentities(lang('If checked, you will be able to create draft messages and save them in the "draft" folder name which you specify in the box for "draft messages folder". Only works with IMAP servers, POP servers do not have folders.'))
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'draft_folder_name',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			//'lang_blurb'	=> lang('Sent messages folder name'),
			'lang_blurb'	=> lang('Sent messages folder'),
			'init_default'	=> 'string,Drafts',
			'values'	=> array(),
			'long_desc'	=> htmlentities(lang('If "draft messages folder" is checked, copies of your draft messages will be stored in the folder name you type in this box. If this folder does not exist, it will be created for you automatically, when you first save a draft message. Default name is "drafts". This will be your "drafts" folder, but it does not have to actually be called "drafts", you can name it anything. Only works with IMAP servers, POP servers do not have folders.'))
		);
		/*
			$i++;
			$this->std_prefs[$i] = array(
				'id' 		=> 'font_size_offset',
				'type'		=> 'known_string',
				'widget'	=> 'combobox',
				'write_props'	=> '',
				'lang_blurb'	=> lang('Change Font Size in your E-Mail Pages'),
				'init_default'	=> 'string,-1',
				'values'	=> array(
					'-2' => lang('Smallest'),
					'-1' => lang('Smaller'),
					'0' => lang('Normal'),
					'1' => lang('Bigger'),
					'2' => lang('Biggest')
				),
				'long_desc' => 
					''
			);
			*/
		/*
			$i++;
			$this->std_prefs[$i] = array(
				'id' 		=> 'p_persistent',
				'type'		=> 'exists',
				'widget'	=> 'checkbox',
				'write_props'	=> '',
				'lang_blurb'	=> lang('persistent email server session'),
				'init_default'	=> 'set_or_not,not_set',
				'values'	=> array(),
				'long_desc' => 
					''
			);
			*/
		// this item has been phased out, not used at the moment
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'cache_data',
			'type'		=> 'exists, INACTIVE',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('cache server data whenever possible'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' =>
			'This option is DEPRECIATED. Not used anymore.'
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'enable_utf7',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('enable UTF-7 encoded folder names'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc'	=> lang('Most US and European users do not need to enable this. If this option is checked then your email server can handle folder names with non US-ASCII charactors in them/ Default is disabled, not checked. Only use if you are really sure you need it. Only works with IMAP servers, POP servers do not have folders.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'fwd_inline_text',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default,extra_accounts',
			// NOTE: write_props value of "group_master" is DEPRECIATED, not used anywhere 
			'lang_blurb'	=> lang('Send forwarded mail as quoted attachment'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' 	=> lang('Select this box if you want the text body of the message you are forwarding to appear inline in the body of your sent message')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'addressbook_choice',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default,extra_accounts',
			'lang_blurb'	=> lang('Select your style for the addressbook. The traditional, simple style. Or the new javascript enabled complex addressbook'),
			'init_default'	=> 'string,simple',
			'values'	=> array(
				'orig' => 'Simple',
				'lex' => 'Javascript'
			),
			'long_desc' => lang('We have recently added this new addressbook so that users can choose to have a more complex addressbook that features a) Easy, point and click searching, b) Best suited for organizations with large central addressbooks with many categories. You can choose here which addressbook do you prefer.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'js_addressbook_screensize',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default,extra_accounts',
			'lang_blurb'	=> lang('Select your screensize for propper showing of the Javascript addressbook'),
			'init_default'	=> 'string,700',
			'values'	=> array(
				'900' => '1200x1600',
				'800' => '1024x768',
				'700' => '800x600'
			),
			'long_desc' => lang('We have three sizes that tell us how to better render the addressbook for you: 800x600 (addressbook will popout in a 700 pixel wide box), 1024x768 (it will be a 800 box), 1200x1600 (will be a 900 box). The fonts for all html stuff will be, respectively set to xx-small, x-small and normal (no font setting).')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id' 		=> 'newmsg_combobox',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Show New Messages in ComboBox'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('This specifies whether or not to show the number of new message in the folders combo box on the index screen.')
		);
		$i++;
		$this->std_prefs[$i] = array(
			'id'            => 'show_foldersize',
			'type'          => 'exists',
			'widget'        => 'checkbox',
			'accts_usage'   => 'default, extra_accounts',
			'write_props'   => '',
			'lang_blurb'    => lang('Show total folder size by default'),
			'init_default'  => 'set_or_not,not_set',
			'values'        => array(),
			'long_desc' => lang('This specifies whether or not to show the total size of folders by default. If this is not checked, you will be presented with a button allowing you to display folder size..')
		);
		// Custom Settings
		$this->cust_prefs = array();
		$i = 0;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'use_custom_settings',
			'type'		=> 'exists',
			'widget'	=> 'checkbox',
			//'accts_usage'	=> 'default, extra_accounts',
			'accts_usage'	=> 'default',
			// NOTE: write_props value of "group_master" is DEPRECIATED, not used anywhere 
			'write_props'	=> 'group_master',
			'lang_blurb'	=> lang('Use custom settings'),
			'init_default'	=> 'set_or_not,not_set',
			'values'	=> array(),
			'long_desc' => lang('Your server administrator will set the default values for the following options. You may never need to change any of them. If you do need to use settings that are different from the defaults for the options below here, then check this box. Default is disabled, not checked. If you fill in some of the options, but later decide to go back to the default values, unchecking this box will erase your custom values and put back the default values. All of the following options start out with the default value, so you may see some settings below even if you have never filled them in. This checkbox only shows up for the default email account. If you are setting up additional email accounts, you will be required to fill in the following options and this checkbox will not be displayed, it will be checked for all extra email accounts.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'userid',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			//'write_props'	=> 'no_db_defang',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Email Account Name'),
			'init_default'	=> 'function,sub_default_userid',
			'values'	=> array(),
			'long_desc' => lang('The login name to use when checking mail for this email account. This may be the same as your phpGroupWare login name, or the server administrator may have set it for you. If your have multiple email accounts set up, you will need to fill this in. If you have only one email account set up, then you can probably leave this alone. If you clear this box, then it goes back to the default value. If you only need some custom settings but want this one to be the default value, then leave this box blank, the default value will be used, and you will see that default value in this box the next time you come to this preferences page.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'passwd',
			'type'		=> 'user_string',
			'widget'	=> 'passwordbox',
			'accts_usage'	=> 'default, extra_accounts',
			//'write_props'	=> 'password, hidden, encrypted, empty_no_delete, no_db_defang',
			'write_props'	=> 'password, hidden, encrypted, empty_no_delete',
			'lang_blurb'	=> lang('Email Password'),
			'init_default'	=> 'init_no_fill',
			'values'	=> array(),
			'long_desc' => lang('The password to use when checking mail for this email account. This may be the same as your phpGroupWare password, or the server administrator may have set it for you. If your have multiple email accounts set up, you will need to fill this in. If you have only one email account set up, then you can probably leave this alone. If you do set a custom password, this box will be blank the next time you come to this settings page. This is a security feature because your custom email password is not sent to your browser after you set it. To change your custom password, simply enter a new password in the box. Extra email accounts require you to set this. For your default email account, you can clear your custom password by unchecking the &quot;Use Custom Settings&quot; option.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' => 'imap_auth_type',
			'type' => 'known_string',
			'widget' => 'combobox',
			'accts_usage' => 'default, extra_accounts',
			'write_props' => 'no_db_defang',
			'lang_blurb' => lang('IMAP authentication'),
			'init_default' => 'string,password',
			'values' => array(
				'password' => lang('Password'),
				'microsoft_oauth' => lang('Microsoft Outlook OAuth2'),
			),
			'long_desc' => lang('Choose password authentication or Microsoft OAuth2 for an Outlook IMAP account. OAuth2 requires the server administrator to configure a Microsoft application.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'address',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			//'write_props'	=> 'no_db_defang',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Email address'),
			//	'init_default'	=> 'function,$this->sub_default_address($account_id);',
			'init_default'	=> 'function,sub_default_address',
			'values'	=> array(),
			'long_desc' => lang('Mail you send will use this address as the &quot;From&quot; address. This may be the same as your phpGroupWare login name, or the server administrator may have set it for you. When the recipient clicks reply, this address will be used. You can leave this box blank and the default value will be used.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'mail_server',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			//'write_props'	=> 'no_db_defang',
			'write_props'	=> '',
			'lang_blurb'	=> lang('Mail Server'),
			'init_default'	=> 'varEVAL,$this->serverSettings["mail_server"];',
			'values'	=> array(),
			'long_desc' => lang('Name of the mail server you want to access. Should be a name like &quot;mail.example.com&quot;. If you leave this box blank then the default value will be used.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'mail_server_type',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> 'no_db_defang',
			'lang_blurb'	=> lang('Mail Server type'),
			'init_default'	=> 'varEVAL,$this->serverSettings["mail_server_type"];',
			'values'	=> array(
				'imap'		=> 'IMAP',
				'pop3'		=> 'POP-3',
				'imaps'		=> 'IMAPS',
				'pop3s'		=> 'POP-3S'
			),
			'long_desc' => lang('The type of mail server you want to access. IMAP mail servers have folders, such as the Sent and Trash folders. POP servers do not have folders. POP, POP-3, and POP3 are the same thing. You can have the server connection encrypted by using IMAPS or POPS, only if the mailserver supports it and if your phpGroupWare installation has a &quot;SSL&quot; capabable version of PHP.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'mail_server_port',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> 'no_db_defang',
			'lang_blurb'	=> lang('Mail Server port'),
			'init_default'	=> 'string,',
			'values'	=> array(),
			'long_desc' => lang('Optional port number for the mail server. Leave blank to use the configured IMAP profile port or the default port for the selected mail server type.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'imap_server_type',
			'type'		=> 'known_string',
			'widget'	=> 'combobox',
			'accts_usage'	=> 'default, extra_accounts',
			'write_props'	=> '',
			'lang_blurb'	=> lang('IMAP Server Type') . ' - ' . lang('If Applicable'),
			'init_default'	=> 'varEVAL,$this->serverSettings["imap_server_type"];',
			'values'	=> array(
				'Cyrus'		=> 'Cyrus ' . lang('or') . ' Courier',
				'UWash'		=> 'UWash',
				'UW-Maildir'	=> 'UW-Maildir'
			),
			'long_desc' => lang('If using an IMAP server, what kind is it, most often this option can safely be set to &quot;Cyrus or Courier&quot;. Technically, this means the server uses a dot between the different parts of the folder names, such as &quot;INBOX.Sent&quot;. The other major kind of IMAP server is the University of Washington &quot;UWash&quot; IMAP server. It uses slashes instead of the dots the other servers use, and although it has a folder called &quot;INBOX&quot;, it is not considered the &quot;Namespace&quot; for the other folder names. The &quot;UW-Maildir&quot; is a rare combination of the two above types. This is the least used kind of IMAP server. If you are unsure, ask your IT administrator. Only applies to IMAP servers.')
		);
		$i++;
		$this->cust_prefs[$i] = array(
			'id' 		=> 'mail_folder',
			'type'		=> 'user_string',
			'widget'	=> 'textbox',
			'accts_usage'	=> 'default, extra_accounts',
			//'write_props'	=> 'empty_string_ok, no_db_defang',
			'write_props'	=> 'empty_string_ok',
			'lang_blurb'	=> lang('U-Wash Mail Folder') . ' - ' . lang('If Applicable'),
			'init_default'	=> 'varEVAL,$this->serverSettings["mail_folder"];',
			'values'	=> array(),
			'long_desc' => lang('Only needed with the University of Washington &quot;UWash&quot; IMAP server. The default value is &quot;mail&quot; which means your mail folders, other then INBOX, are located in a directory called &quot;mail&quot; directly under your &quot;HOME&quot; directory. This box may be left empty, which means your mail folders are located in your &quot;HOME&quot; directory, not a subdirectory. If your mail folders are located in a subdirectory of &quot;HOME&quot; then put the name of that subdirectory here. Generally, it is not necessary to use any special slashes or tildes, &quot;HOME&quot; is always considered the base directory, and the slash bewteen &quot;HOME&quot; and the subdirectory will be added for you automatically, do not put the slash in this box.')
		);
		return ['standard' => $this->std_prefs, 'custom' => $this->cust_prefs];
	}
}
