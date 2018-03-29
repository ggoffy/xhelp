<?php namespace XoopsModules\Xhelp\Faq;

use XoopsModules\Xhelp;

//Sanity Check: make sure that file is not being accessed directly
if (!defined('XHELP_CLASS_PATH')) {
    exit();
}

// ** Define any site specific variables here **
define('XHELP_SMARTFAQ_PATH', XOOPS_ROOT_PATH . '/modules/smartfaq');
define('XHELP_SMARTFAQ_URL', XOOPS_URL . '/modules/smartfaq');
// ** End site specific variables **

//Include the base faqAdapter interface (required)
// require_once XHELP_CLASS_PATH . '/faqAdapter.php';

//These functions are required to work with the smartfaq application directly
@include XHELP_SMARTFAQ_PATH . '/include/functions.php';

/**
 * class SmartfaqAdapter
 */
class SmartfaqAdapter extends Xhelp\FaqAdapter
{
    /**
     * Does application support categories?
     * Possible Values:
     * XHELP_FAQ_CATEGORY_SING - entries can be in 1 category
     * XHELP_FAQ_CATEGORY_MULTI - entries can be in more than 1 category
     * XHELP_FAQ_CATEGORY_NONE - No category support
     * @access public
     */
    public $categoryType = XHELP_FAQ_CATEGORY_SING;

    /**
     * Adapter Details
     * Required Values:
     * name - name of adapter
     * author - who wrote the plugin
     * author_email - contact email
     * version - version of this plugin
     * tested_versions - supported application versions
     * url - support url for plugin
     * module_dir - module directory name (not needed if class overloads the isActive() function from Xhelp\FaqAdapter)
     * @access public
     */
    public $meta = [
        'name'            => 'Smartfaq',
        'author'          => 'Eric Juden',
        'author_email'    => 'eric@3dev.org',
        'description'     => 'Create SmartFAQ entries from xHelp helpdesk tickets',
        'version'         => '1.0',
        'tested_versions' => '1.04',
        'url'             => 'http://www.smartfactory.ca/',
        'module_dir'      => 'smartfaq'
    ];

    /**
     * Class Constructor (Required)
     */
    public function __construct()
    {
        // Every class should call parent::init() to ensure that all class level
        // variables are initialized properly.
        parent::init();
    }

    /**
     * getCategories: retrieve the categories for the module
     * @return array Array of Xhelp\FaqCategory
     */
    public function &getCategories()
    {
        $ret = [];
        // Create an instance of the Xhelp\FaqCategoryHandler
        $hFaqCategory = new Xhelp\FaqCategoryHandler($GLOBALS['xoopsDB']);

        // Get all the categories for the application
        $hSmartCategory = \XoopsModules\Smartfaq\Helper::getInstance()->getHandler('Category');
        $categories     = $hSmartCategory->getCategories(0, 0, -1);

        //Convert the module specific category to the
        //Xhelp\FaqCategory object for standarization
        foreach ($categories as $category) {
            $faqcat = $hFaqCategory->create();
            $faqcat->setVar('id', $category->getVar('categoryid'));
            $faqcat->setVar('parent', $category->getVar('parentid'));
            $faqcat->setVar('name', $category->getVar('name'));
            $ret[] = $faqcat;
        }
        unset($categories);

        return $ret;
    }

    /**
     * storeFaq: store the FAQ in the application's specific database (required)
     * @param  Xhelp\Faq $faq The faq to add
     * @return bool     true (success) / false (failure)
     * @access public
     */
    public function storeFaq($faq = null)
    {
        global $xoopsUser;
        $uid = $xoopsUser->getVar('uid');

        // Take Xhelp\Faq and create faq for smartfaq
        $hFaq     = \XoopsModules\Smartfaq\Helper::getInstance()->getHandler('Faq');
        $hAnswer  = \XoopsModules\Smartfaq\Helper::getInstance()->getHandler('Answer');
        $myFaq    = $hFaq->create();
        $myAnswer = $hAnswer->create();            // Creating the answer object

        //$faq->getVar('categories') is an array. If your application
        //only supports single categories use the first element
        //in the array
        $categories = $faq->getVar('categories');
        $categories = (int)$categories[0];       // Change array of categories to 1 category

        $myFaq->setVar('uid', $uid);
        $myFaq->setVar('question', $faq->getVar('problem'));
        $myFaq->setVar('datesub', time());
        $myFaq->setVar('categoryid', $categories);
        $myFaq->setVar('status', _SF_STATUS_PUBLISHED);

        $ret = $hFaq->insert($myFaq);
        $faq->setVar('id', $myFaq->getVar('faqid'));

        if ($ret) {   // If faq was stored, store answer
            // Trigger event for question being stored

            $myAnswer->setVar('status', _SF_AN_STATUS_APPROVED);
            $myAnswer->setVar('faqid', $myFaq->faqid());
            $myAnswer->setVar('answer', $faq->getVar('solution'));
            $myAnswer->setVar('uid', $uid);

            $ret = $hAnswer->insert($myAnswer);
        }

        if ($ret) {
            // Set the new url for the saved FAQ
            $faq->setVar('url', $this->makeFaqUrl($faq));

            // Trigger any module events
            $myFaq->sendNotifications([_SF_NOT_FAQ_PUBLISHED]);
        }

        return $ret;
    }

    /**
     * Create the url going to the faq article
     *
     * @param $faq Xhelp\Faq object
     * @return string
     * @access private
     */
    public function makeFaqUrl(&$faq)
    {
        return XHELP_SMARTFAQ_URL . '/faq.php?faqid=' . $faq->getVar('id');
    }
}
