import { TabPanel, Animate, Notice } from "@wordpress/components";
import GlobalSettings from "../components/GlobalSettings";
import ProductSettings from "./../components/ProductSettings";
import CartSettings from "./../components/CartSettings";
import PromotionSettings from "./../components/PromotionSettings";
import AdvancedSettings from "./../components/AdvancedSettings";
import { useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import Loader from "../components/Loader";
import { useToast } from "../store/toast/use-toast";
import { check, Icon } from "@wordpress/icons";
import { __ } from "@wordpress/i18n";
const Settings = () => {
  const [settings, setSettings] = useState({});
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isSaving, setIsSaving] = useState(false);
  const { addToast } = useToast();
  const [productSettings, setProductSettings] = useState({});
  const [globalSettings, setGlobalSettings] = useState({});
  const [cartSettings, setCartSettings] = useState({});
  const [promotionSettings, setPromotionSettings] = useState({});
  const [advancedSettings, setAdvancedSettings] = useState({});
  const [formData, setFormData] = useState("global");

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const response = await apiFetch({
        path: "/campaignbay/v1/settings?_timestamp=" + Date.now(),
      });
      setSettings(response);
      setIsLoading(false);
    } catch (error) {
      console.error("Error fetching settings:", error);
      setError("Failed to load settings");
      // setIsLoading(false);
      addToast(
        __("Something went wrong . Please refresh the page.", "campaignbay"),
        "error"
      );
    }
  };
  // {
  // "global_enableAddon": false,
  // "global_defaultPriority": 10,
  // "global_calculationMode": "after_tax",
  // "global_decimalPlaces": 2,
  // "perf_enableCaching": true,
  // "debug_enableMode": false,
  // "debug_logLevel": ["errors_only"],

  // "product_showDiscountedPrice": true,
  // "product_messageFormat": "You save {percentage_off}!",
  // "product_enableQuantityTable": true,
  // "product_excludeSaleItems": true,
  // "product_priorityMethod": "apply_highest",

  // "cart_allowWcCouponStacking": false,
  // "cart_allowCampaignStacking": false,

  // "cart_savedMessageFormat": "You saved {saved_amount} on this order!",
  // "cart_showNextDiscountBar": true,
  // "cart_nextDiscountFormat": "Spend {remaining_amount} more for {discount_percentage} off!",
  // "cart_showDiscountBreakdown": true,

  // "promo_enableBar": false,
  // "promo_barPosition": "top_of_page",
  // "promo_barBgColor": "#000000",
  // "promo_barTextColor": "#FFFFFF",
  // "promo_barContent": "FLASH SALE! {percentage_off} on all shirts!",
  // "promo_barLinkUrl": "",
  // "promo_barDisplayPages": [
  //     "shop_page",
  //     "product_pages"
  // ],
  // "promo_enableCustomBadges": true,
  // "advanced_deleteAllOnUninstall": false,
  // "advanced_customCss": "",
  // "advanced_customJs": "",
  // "_locale": "user"
  // }
  useEffect(() => {
    if (!settings) {
      return;
    }
    setProductSettings({
      product_showDiscountedPrice: settings.product_showDiscountedPrice,
      product_messageFormat: settings.product_messageFormat,
      product_enableQuantityTable: settings.product_enableQuantityTable,
      product_excludeSaleItems: settings.product_excludeSaleItems,
      product_priorityMethod: settings.product_priorityMethod,
    });

    setGlobalSettings({
      global_enableAddon: settings.global_enableAddon,
      global_defaultPriority: settings.global_defaultPriority,
      global_calculationMode: settings.global_calculationMode,
      global_decimalPlaces: settings.global_decimalPlaces,
      perf_enableCaching: settings.perf_enableCaching,
      debug_enableMode: settings.debug_enableMode,
      debug_logLevel: settings.debug_logLevel,
    });
    setPromotionSettings({
      promo_enableBar: settings.promo_enableBar,
      promo_barPosition: settings.promo_barPosition,
      promo_barBgColor: settings.promo_barBgColor,
      promo_barTextColor: settings.promo_barTextColor,
      promo_barContent: settings.promo_barContent,
    });
    setCartSettings({
      cart_allowWcCouponStacking: settings.cart_allowWcCouponStacking,
      cart_allowCampaignStacking: settings.cart_allowCampaignStacking,
      cart_savedMessageFormat: settings.cart_savedMessageFormat,
      cart_showNextDiscountBar: settings.cart_showNextDiscountBar,
      cart_nextDiscountFormat: settings.cart_nextDiscountFormat,
      cart_showDiscountBreakdown: settings.cart_showDiscountBreakdown,
    });
    setAdvancedSettings({
      advanced_deleteAllOnUninstall: settings.advanced_deleteAllOnUninstall,
      advanced_customCss: settings.advanced_customCss,
      advanced_customJs: settings.advanced_customJs,
    });
  }, [settings]);

  const updateSettings = async () => {
    try {
      setIsSaving(true);
      console.log(formData);
      let data = {};
      switch (formData) {
        case "global":
          data = {
            ...globalSettings,
          };
          break;
        case "product":
          data = {
            ...productSettings,
          };
          break;
        case "cart":
          data = {
            ...cartSettings,
          };
          break;
        case "promotion":
          data = {
            ...promotionSettings,
          };
          break;
        case "advanced":
          data = {
            ...advancedSettings,
          };
          break;
        default:
          data = {
            ...globalSettings,
          };
          break;
      }
      // console.log(data);
      console.log(cartSettings);
      const response = await apiFetch({
        path: "/campaignbay/v1/settings",
        method: "POST",
        data: {
          ...settings,
          ...data,
        },
      });
      setSettings(response);
      setIsSaving();
      addToast(__("Settings updated successfully", "campaignbay"), "success");
    } catch (error) {
      console.log(error);
      setError(error);
      setIsSaving(false);
      addToast(
        __("Something went wrong. Please try again.", "campaignbay"),
        "error"
      );
    }
  };
  console.log(cartSettings);
  if (isLoading) {
    return <Loader />;
  }

  return (
    <div className="wpab-cb-page">
      <div className="wpab-cb-page-header">
        <div className="cb-container">
          <h1 className="wpab-cb-page-header-text campaignbay-text-2xl">
            {" "}
            {__("CampaignBay Settings", "campaignbay")}
          </h1>
          <button
            className="wpab-cb-btn wpab-cb-btn-primary"
            disabled={isSaving}
            onClick={updateSettings}
          >
            <Icon icon={check} fill="currentColor" />
            {__("Save Settings", "campaignbay")}
          </button>
        </div>
      </div>
      {/* <div className="wpab-cb-settings-tabs-container"> */}
      <TabPanel
        className="wpab-cb-settings-tabs"
        // activeClass='wpab-cb-settings-active-tab'
        tabs={[
          {
            name: "global",
            title: "Global Settings",
          },
          {
            name: "product",
            title: "Product Settings",
          },
          {
            name: "cart",
            title: "Cart Settings",
          },
          // {
          //     name: 'promotion',
          //     title: 'Promotion Settings'
          // },
          // {
          //     name: 'advanced',
          //     title: 'Advanced Settings'
          // }
        ]}
      >
        {(tab) =>
          (() => {
            switch (tab.name) {
              case "global":
                setFormData("global");
                return (
                  <GlobalSettings
                    globalSettings={globalSettings}
                    setGlobalSettings={setGlobalSettings}
                  />
                );
              case "product":
                setFormData("product");
                return (
                  <ProductSettings
                    productSettings={productSettings}
                    setProductSettings={setProductSettings}
                  />
                );
              case "cart":
                setFormData("cart");
                return (
                  <CartSettings
                    cartSettings={cartSettings}
                    setCartSettings={setCartSettings}
                  />
                );
              case "promotion":
                setFormData("promotion");
                return (
                  <PromotionSettings
                    promotionSettings={promotionSettings}
                    setPromotionSettings={setPromotionSettings}
                  />
                );
              case "advanced":
                setFormData("advanced");
                return (
                  <AdvancedSettings
                    advancedSettings={advancedSettings}
                    setAdvancedSettings={setAdvancedSettings}
                  />
                );
              default:
                setFormData("global");
                return (
                  <GlobalSettings
                    globalSettings={globalSettings}
                    setGlobalSettings={setGlobalSettings}
                  />
                );
            }
          })()
        }
      </TabPanel>
      <div className="wpab-button-con-card">
        <div className="cb-container ">
          <button
            className="wpab-cb-btn wpab-cb-btn-primary"
            disabled={isSaving}
            onClick={updateSettings}
          >
            <Icon icon={check} fill="currentColor" />
            {__("Save Changes", "campaignbay")}
          </button>
        </div>
      </div>
      {/* </div> */}
    </div>
  );
};
export default Settings;
