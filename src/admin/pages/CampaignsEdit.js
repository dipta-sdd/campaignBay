import { useParams, useNavigate } from "react-router-dom";
import { useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import MultiSelect from "../components/Multiselect";
import {
  TimePicker,
  __experimentalConfirmDialog as ConfirmDialog,
  __experimentalToggleGroupControl as ToggleGroupControl,
  __experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from "@wordpress/components";
import { check, Icon, pencil, trash } from "@wordpress/icons";
import { __ } from "@wordpress/i18n";
import { useToast } from "../store/toast/use-toast";
import { useEffect } from "react";
import Required from "../components/Required";
import QuantityTiers from "../components/QuantityTiers";
import EBTiers from "../components/EBTiers";
import { useCbStore } from "../store/cbStore";
import { getSettings as getDateSettings } from "@wordpress/date";
import Loader from "../components/Loader";

const CampaignsEdit = () => {
  const navigate = useNavigate();
  const { id } = useParams();
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const { woocommerce_currency_symbol } = useCbStore();
  const [campaignType, setCampaignType] = useState("scheduled");
  const [campaignStatus, setCampaignStatus] = useState("scheduled");
  const [campaignTitle, setCampaignTitle] = useState("");
  const [selectionType, setSelectionType] = useState("entire_store");
  const [selections, setSelections] = useState([]);
  const [discountType, setDiscountType] = useState("percentage");
  const [discountValue, setDiscountValue] = useState("");
  const [startDate, setStartDate] = useState(new Date());
  const [endDate, setEndDate] = useState("");
  const { addToast } = useToast();
  const [categories, setCategories] = useState([]);
  const [products, setProducts] = useState([]);
  const [tags, setTags] = useState([]);
  const [quantityTiers, setQuantityTiers] = useState([
    {
      id: 0,
      min: 1,
      max: "",
      value: "",
      type: "percentage",
    },
  ]);
  const [ebTiers, setEBTiers] = useState([
    {
      id: 0,
      quantity: null,
      value: null,
      type: "percentage",
      total: 0,
    },
  ]);
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [errors, setErrors] = useState({});
  useEffect(() => {
    const fetchCampaign = async () => {
      const response = await apiFetch({
        path: `/campaignbay/v1/campaigns/${id}?_timestamp=${Date.now()}`,
      });
      setCampaignStatus(response.status);
      setCampaignType(response.campaign_type);
      setCampaignTitle(response.title);
      setSelectionType(response.target_type);
      setSelections(response.target_ids);
      setDiscountType(response.discount_type);
      setDiscountValue(response.discount_value);
      setStartDate(response.start_datetime);
      setEndDate(response.end_datetime);
      if (response.campaign_type === "quantity") {
        setQuantityTiers([...response.campaign_tiers]);
      } else if (response.campaign_type === "earlybird") {
        setEBTiers([...response.campaign_tiers]);
      }
      setIsLoading(false);
    };
    fetchCampaign();
    fetchCategories();
    fetchProducts();
  }, [id]);

  useEffect(() => {
    Promise.all([fetchCategories(), fetchProducts(), fetchTags()]);
  }, []);

  const fetchCategories = async () => {
    try {
      const response = await apiFetch({
        path: "/wc/v3/products/categories?per_page=-1&_timestamp=" + Date.now(),
      });
      setCategories(
        response.map((item) => ({
          label: item.name,
          value: item.id,
        }))
      );
    } catch (error) {
      console.error("Error fetching categories:", error);
      addToast(
        __("Something went wrong, Please reload the page.", "campaignbay"),
        "error"
      );
    }
  };
  const fetchProducts = async () => {
    try {
      const response = await apiFetch({
        path: "/wc/v3/products?per_page=-1&_timestamp=" + Date.now(),
      });
      setProducts(
        response.map((item) => ({
          label: item.name,
          value: item.id,
        }))
      );
    } catch (error) {
      console.error("Error fetching Products:", error);
      addToast(
        __("Something went wrong, Please reload the page.", "campaignbay"),
        "error"
      );
    }
  };
  const fetchTags = async () => {
    try {
      const response = await apiFetch({
        path: "/wc/v3/products/tags?per_page=-1&_timestamp=" + Date.now(),
      });
      setTags(
        response.map((item) => ({
          label: item.name,
          value: item.id,
        }))
      );
    } catch (error) {
      console.error("Error fetching Products:", error);
      addToast(
        __("Something went wrong, Please reload the page.", "campaignbay"),
        "error"
      );
    }
  };

  const handleSelectionTypeChange = (value) => {
    setSelectionType(value);
    setSelections([]);
  };

  const handleCampaignTypeChange = (value) => {
    setCampaignType(value);
    if (value === "scheduled") {
      setCampaignStatus("cb_scheduled");
    }
  };

  const handleCampaignStatusChange = (value) => {
    setCampaignStatus(value);
  };

  const { timezone } = getDateSettings();
  const handleSaveCampaign = async () => {
    const campaignData = {
      title: campaignTitle,
      status: campaignStatus,
      campaign_type: campaignType,
      discount_type: discountType,
      discount_value: discountValue || 0,
      target_type: selectionType,
      target_ids: selections,
      start_datetime: startDate,
      end_datetime: endDate || null,
      timezone_offset: timezone.offsetFormatted,
      campaign_tiers:
        campaignType === "quantity"
          ? quantityTiers
          : campaignType === "earlybird"
          ? ebTiers
          : [],
    };
    // console.log(campaignData);
    if (!campaignData?.title) {
      setErrors({ title: "Title is required" });
      return;
    }
    if (!campaignData?.status && campaignType !== "scheduled") {
      setErrors({ status: "Status is required" });
      return;
    }
    if (!campaignData?.campaign_type) {
      setErrors({ campaign_type: "Campaign type is required" });
      return;
    }
    if (campaignData.campaign_type === "scheduled") {
      if (!campaignData?.discount_type) {
        setErrors({ discount_type: "Discount type is required" });
        return;
      }
      if (!campaignData?.discount_value) {
        setErrors({ discount_value: "Discount value is required" });
        return;
      }
    } else {
      if (!campaignData?.target_type) {
        setErrors({ target_type: "Target type is required" });
        return;
      }
      if (
        campaignData.target_type !== "entire_store" &&
        !campaignData?.target_ids
      ) {
        setErrors({ target_ids: "Target ids are required" });
        return;
      }
    }
    if (
      campaignData.campaign_status === "cb_scheduled" &&
      !campaignData?.start_datetime
    ) {
      setErrors({ start_datetime: "Start datetime is required" });
      return;
    }
    if (
      campaignData.campaign_status === "cb_scheduled" &&
      !campaignData?.end_datetime
    ) {
      setErrors({ end_datetime: "End datetime is required" });
      return;
    }
    console.log("start_datetime", campaignData.start_datetime);
    console.log("end_datetime", campaignData.end_datetime);
    try {
      setIsSaving(true);
      const response = await apiFetch({
        path: "/campaignbay/v1/campaigns/" + id,
        method: "POST",
        data: campaignData,
      });
      setIsSaving(false);
      addToast(__("Campaign saved successfully", "campaignbay"), "success");
      navigate("/campaigns");
    } catch (error) {
      setIsSaving(false);
      if (error?.code === "rest_invalid_param") {
        setErrors(error?.data?.params);
      }
      addToast(
        __("Something went wrong, Please reload the page.", "campaignbay"),
        "error"
      );
      console.log(error);
    }
  };

  const handleDeleteCampaign = async () => {
    try {
      setIsDeleting(true);
      const response = await apiFetch({
        path: "/campaignbay/v1/campaigns/" + id,
        method: "DELETE",
      });
      setIsDeleteModalOpen(false);
      addToast(__("Campaign deleted successfully", "campaignbay"), "success");
      navigate("/campaigns");
    } catch (error) {
      addToast(
        __("Something went wrong, Please reload the page.", "campaignbay"),
        "error"
      );
    } finally {
      setIsDeleting(false);
    }
  };
  return (
    <>
      {isLoading ? (
        <Loader />
      ) : (
        <div className="cb-page">
          <div className="cb-page-header-container">
            <div className="cb-page-header-title">
              {!isEditingTitle ? (
                <span>{campaignTitle}</span>
              ) : (
                <input
                  className="wpab-input"
                  type="text"
                  value={campaignTitle}
                  onChange={(e) => setCampaignTitle(e.target.value)}
                />
              )}
              {isEditingTitle ? (
                <Icon
                  icon={check}
                  className="cb-page-header-title-icon"
                  fill="currentColor"
                  onClick={() => setIsEditingTitle(false)}
                />
              ) : (
                <Icon
                  icon={pencil}
                  className="cb-page-header-title-icon"
                  fill="currentColor"
                  onClick={() => setIsEditingTitle(true)}
                />
              )}
            </div>
            <div className="cb-page-header-actions">
              <button
                className="wpab-cb-btn wpab-cb-btn-danger "
                disabled={isDeleting}
                onClick={handleDeleteCampaign}
              >
                <Icon icon={trash} fill="currentColor" />
                {__("Delete Campaign", "campaignbay")}
              </button>
              <button
                className="wpab-cb-btn wpab-cb-btn-primary "
                disabled={isSaving}
                onClick={handleSaveCampaign}
              >
                <Icon icon={check} fill="currentColor" />
                {__("Update Campaign", "campaignbay")}
              </button>
            </div>
          </div>
          <div className="cb-page-container">
            <div className="cb-form-input-con">
              <label htmlFor="campaign-type">
                {__("SELECT DISCOUNT TYPE", "campaignbay")} <Required />
              </label>
              <select
                type="text"
                id="campaign-type"
                className={`wpab-input w-100 ${
                  errors?.campaign_type ? "wpab-input-error" : ""
                }`}
                value={campaignType}
                onChange={(e) => handleCampaignTypeChange(e.target.value)}
              >
                <option value="scheduled">
                  {__("Scheduled Discount", "campaignbay")}
                </option>
                <option value="quantity">
                  {__("Quantity Based Discount", "campaignbay")}
                </option>
                <option value="earlybird">
                  {__("EarlyBird Discount", "campaignbay")}
                </option>
              </select>
            </div>

            <div className="cb-form-input-con">
              <label htmlFor="campaign-status">
                {__("SELECT STATUS", "campaignbay")} <Required />
              </label>
              <select
                type="text"
                id="campaign-status"
                className={`wpab-input w-100 ${
                  errors?.status ? "wpab-input-error" : ""
                }`}
                value={campaignStatus}
                onChange={(e) => handleCampaignStatusChange(e.target.value)}
              >
                <option value="cb_scheduled">
                  {__("Active", "campaignbay")}
                </option>
                <option value="campaignbay_inactive">
                  {__("Inactive", "campaignbay")}
                </option>
                <option value="cb_scheduled">
                  {__("Scheduled", "campaignbay")}
                </option>
                <option value="campaignbay_expired">
                  {__("Expired", "campaignbay")}
                </option>
              </select>
            </div>
            <div className="cb-form-input-con">
              <label htmlFor="selection-type">
                {__("SELECT FOR USERS", "campaignbay")} <Required />
              </label>
              <select
                type="text"
                id="selection-type"
                className={`wpab-input w-100 ${
                  errors?.target_type ? "wpab-input-error" : ""
                }`}
                value={selectionType}
                onChange={(e) => handleSelectionTypeChange(e.target.value)}
              >
                <option value="entire_store">
                  {__("Entire Store", "campaignbay")}
                </option>
                <option value="category">
                  {__("By Product Category", "campaignbay")}
                </option>
                <option value="product">
                  {__("By Product", "campaignbay")}
                </option>
                <option value="tag">{__("By Tags", "campaignbay")}</option>
              </select>

              {selectionType !== "entire_store" ? (
                <div
                  style={{ background: "#ffffff" }}
                  className={`${errors?.target_ids ? "wpab-input-error" : ""}`}
                >
                  <MultiSelect
                    label={
                      selectionType === "product"
                        ? __("Select Products *", "campaignbay")
                        : selectionType === "tag"
                        ? __("Select Tags *", "campaignbay")
                        : selectionType === "category"
                        ? __("Select Categories *", "campaignbay")
                        : ""
                    }
                    options={
                      selectionType === "product"
                        ? products
                        : selectionType === "tag"
                        ? tags
                        : selectionType === "category"
                        ? categories
                        : []
                    }
                    value={selections}
                    onChange={setSelections}
                  />
                </div>
              ) : null}
            </div>

            {campaignType === "quantity" && (
              <QuantityTiers
                className={`${
                  errors?.campaign_tiers ? "wpab-input-error" : ""
                }`}
                tiers={quantityTiers}
                setTiers={setQuantityTiers}
                errors={errors}
              />
            )}

            {campaignType === "earlybird" && (
              <EBTiers
                className={`${
                  errors?.campaign_tiers ? "wpab-input-error" : ""
                }`}
                tiers={ebTiers}
                setTiers={setEBTiers}
                errors={errors}
              />
            )}

            {campaignType === "scheduled" && (
              <div className="cb-form-input-con">
                <label htmlFor="discount-type">
                  {__("How many you want to discount?", "campaignbay")}{" "}
                  <Required />
                </label>
                <ToggleGroupControl
                  className={`cb-toggle-group-control ${
                    errors?.discount_type ? "wpab-input-error" : ""
                  }`}
                  __next40pxDefaultSize
                  __nextHasNoMarginBottom
                  isBlock
                  value={discountType}
                  onChange={(value) => setDiscountType(value)}
                >
                  <ToggleGroupControlOption
                    label={__("Percentage %", "campaignbay")}
                    value="percentage"
                  />
                  <ToggleGroupControlOption
                    label={
                      __("Currency ", "campaignbay") +
                      (woocommerce_currency_symbol || "$")
                    }
                    value="fixed"
                  />
                </ToggleGroupControl>
                <span className="wpab-input-help">
                  {__("If you want you will change mode", "campaignbay")}
                </span>

                <div className="cb-input-with-suffix">
                  <input
                    value={discountValue}
                    type="text"
                    name="discount-value"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    className={`wpab-input w-100 ${
                      errors?.discount_value ? "wpab-input-error" : ""
                    }`}
                    placeholder="Enter Value"
                    onChange={(e) => setDiscountValue(parseInt(e.target.value))}
                  />
                  <span className="cb-suffix">
                    {discountType === "percentage"
                      ? "%"
                      : woocommerce_currency_symbol || "$"}
                  </span>
                </div>
              </div>
            )}
            {campaignStatus === "cb_scheduled" && (
              <div className="cb-form-input-con">
                <label htmlFor="start-time">
                  {__("SELECT CAMPAIGN DURATION", "campaignbay")} <Required />
                </label>
                <div
                  className="wpab-grid-2 cb-date-time-fix"
                  style={{ gap: "16px" }}
                >
                  <div
                    className={`${
                      errors?.start_datetime ? "wpab-input-error" : ""
                    }`}
                  >
                    <span
                      className="wpab-input-label"
                      style={{ display: "block", marginBottom: "10px" }}
                    >
                      {__("Start Time", "campaignbay")}
                    </span>
                    <TimePicker
                      id="start-time"
                      currentTime={startDate}
                      onChange={(date) => {
                        setStartDate(date);
                      }}
                    />
                  </div>
                  <div
                    className={`${
                      errors?.end_datetime ? "wpab-input-error" : ""
                    }`}
                  >
                    <span
                      className="wpab-input-label"
                      style={{ display: "block", marginBottom: "10px" }}
                    >
                      {__("End Time", "campaignbay")}
                    </span>
                    <TimePicker
                      id="end-time"
                      currentTime={endDate ? endDate : null}
                      onChange={(date) => {
                        setEndDate(date);
                      }}
                    />
                  </div>
                </div>
              </div>
            )}
            <div className="wpab-btn-bottom-con">
              <button
                className="wpab-cb-btn wpab-cb-btn-primary"
                onClick={handleSaveCampaign}
              >
                <Icon icon={check} fill="currentColor" />
                {__("Save Changes", "campaignbay")}
              </button>
            </div>
          </div>
        </div>
      )}

      <ConfirmDialog
        isOpen={isDeleteModalOpen}
        onConfirm={handleDeleteCampaign}
        onCancel={() => setIsDeleteModalOpen(false)}
      >
        {__("Are you sure you want to delete this campaign?", "campaignbay")}
      </ConfirmDialog>
    </>
  );
};

export default CampaignsEdit;
