import { useState, useMemo, useEffect } from "react";
import { __, _n, sprintf } from "@wordpress/i18n";
import { useNavigate } from "react-router-dom";
import {
  Icon,
  plus,
  search,
  chevronUp,
  chevronDown,
  trash,
  moreVertical,
  previous,
  next,
  edit,
  check,
  cancelCircleFilled,
} from "@wordpress/icons";
import apiFetch from "@wordpress/api-fetch";
import {
  __experimentalConfirmDialog as ConfirmDialog,
  ToolbarDropdownMenu,
} from "@wordpress/components";
import { useToast } from "../store/toast/use-toast";
import CbCheckbox from "../components/CbCheckbox"; // Assuming you still use this for the header
import { addQueryArgs } from "@wordpress/url";
import { useCbStore } from "../store/cbStore";
import { date, getDate } from "@wordpress/date";
import Skeleton from "../components/Skeleton";

const Campaigns = () => {
  const [campaigns, setCampaigns] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const navigate = useNavigate();
  const { addToast } = useToast();
  const { wpSettings, woocommerce_currency_symbol } = useCbStore();
  const [selectedCampaignId, setSelectedCampaignId] = useState(null);
  const [selectedCampaigns, setSelectedCampaigns] = useState([]);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [orderby, setOrderby] = useState("modified");
  const [order, setOrder] = useState("desc");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [bulkAction, setBulkAction] = useState("");
  const [isBulkActionModalOpen, setIsBulkActionModalOpen] = useState(false);

  const tableHeads = [
    { label: "Campaign Name", value: "post_name", isSortable: true },
    { label: "Status", value: "status", isSortable: true },
    { label: "Campaign Type", value: "campaign_type", isSortable: true },
    { label: "Target", value: "target", isSortable: false },
    { label: "Value", value: "value", isSortable: false },
    { label: "Start Date", value: "start_date", isSortable: true },
    { label: "End Date", value: "end_date", isSortable: true },
    { label: "Usage", value: "usage_count", isSortable: true },
    { label: "Last Modified", value: "modified", isSortable: true },
    { label: " ", value: "action", isSortable: false },
  ];

  useEffect(() => {
    fetchCampaigns();
  }, [orderby, order, searchQuery, currentPage, itemsPerPage]);
  const fetchCampaigns = async () => {
    try {
      setIsLoading(true);
      const queryParams = {
        page: currentPage,
        per_page: itemsPerPage,
        orderby: orderby,
        order: order,
        search: searchQuery,
        status: statusFilter,
        type: typeFilter,
        _timestamp: Date.now(),
      };
      const response = await apiFetch({
        path: addQueryArgs("/campaignbay/v1/campaigns", queryParams),
        method: "GET",
        parse: false,
      });

      setTotalPages(response.headers.get("x-wp-totalpages"));
      setTotalItems(response.headers.get("x-wp-total"));
      setCampaigns(await response.json());
    } catch (error) {
      addToast(__("Error fetching campaigns.", "campaignbay"), "error");
    } finally {
      setIsLoading(false);
    }
  };

  const formatDateTime = (dateTimeString) => {
    if (
      !dateTimeString ||
      new Date(dateTimeString).toString() === "Invalid Date"
    ) {
      return "—"; // Return an em-dash for missing dates.
    }

    // get the date format and time format from the wpSettings
    const format = `${wpSettings.dateFormat} ${wpSettings.timeFormat}`;

    // get the date and time from the dateTimeString
    const dateTime = getDate(dateTimeString);

    // format the date and time
    return date(format, dateTime, null);
  };

  const handleSelectAll = () => {
    if (isAllSelected) {
      setSelectedCampaigns([]);
    } else {
      setSelectedCampaigns(campaigns.map((c) => c.id));
    }
  };

  const handleSelectCampaign = (campaignId) => {
    if (selectedCampaigns.includes(campaignId)) {
      setSelectedCampaigns((prev) => prev.filter((id) => id !== campaignId));
    } else {
      setSelectedCampaigns((prev) => [...prev, campaignId]);
    }
  };

  const handleCampaignDelete = async () => {
    try {
      await apiFetch({
        path: `/campaignbay/v1/campaigns/${selectedCampaignId}`,
        method: "DELETE",
      });
      addToast(__("Campaign deleted successfully", "campaignbay"), "success");
      setCampaigns((prev) => prev.filter((c) => c.id !== selectedCampaignId));
      setSelectedCampaigns((prev) =>
        prev.filter((id) => id !== selectedCampaignId)
      );
      setIsDeleteModalOpen(false);
    } catch (error) {
      addToast(__("Error deleting campaign", "campaignbay"), "error");
      setIsDeleteModalOpen(false);
    }
  };

  const handleSort = (newSortBy) => {
    if (orderby === newSortBy) {
      setOrder(order === "asc" ? "desc" : "asc");
    } else {
      // If clicking a new column, set it as the sort column and default to ascending.
      setOrderby(newSortBy);
      setOrder("asc");
    }
    console.log(orderby, order);
  };

  const TableHead = ({ label, isSortable, value, onClick }) => {
    if (isSortable) {
      return (
        <th>
          <span
            className="campaignbay-table-header campaignbay-table-header-sortable"
            onClick={onClick}
          >
            {label}
            <SortIndicator value={value} />
          </span>
        </th>
      );
    }
    return (
      <th>
        <span className="campaignbay-table-header">{label}</span>
      </th>
    );
  };

  const SortIndicator = ({ value }) => {
    if (orderby !== value) {
      return null; // Don't show an icon if it's not the active sort column
    }
    return (
      <Icon
        className="campaignbay-table-header-icon"
        icon={order === "asc" ? chevronUp : chevronDown}
        fill="currentColor"
      />
    );
  };
  const isAllSelected =
    campaigns.length > 0 && selectedCampaigns.length === campaigns.length;

  const getTargetType = (target_type) => {
    if (target_type === "product") {
      return "Selected Products";
    }
    if (target_type === "category") {
      return "Selected Categories";
    }
    if (target_type === "entire_store") {
      return "All Products";
    }
    return "All Products";
  };
  const applyFilters = () => {
    if (currentPage !== 1) {
      setCurrentPage(1);
    } else {
      fetchCampaigns();
    }
  };

  const handleBulkAction = (action) => {
    setBulkAction(action);
    if (action !== "" && action !== null && action !== undefined) {
      setIsBulkActionModalOpen(true);
    }
  };

  const handleBulkActionModal = () => {
    if (!selectedCampaigns?.length || !bulkAction || bulkAction === "") {
      setIsBulkActionModalOpen(false);
      return;
    }
    if (bulkAction === "delete") {
      handleCampaignsDelete();
    } else if (bulkAction === "activate") {
      handleCampaignsStatusUpdate();
    } else if (bulkAction === "deactivate") {
      handleCampaignsStatusUpdate();
    }

    setIsBulkActionModalOpen(false);
    setIsLoading(false);
  };

  const bulkActionLabels = {
    "": __("Select Action", "campaignbay"), // Default
    activate: __("activate", "campaignbay"),
    deactivate: __("deactivate", "campaignbay"),
    delete: __("delete", "campaignbay"),
  };

  const confirmationMessage = sprintf(
    /* translators: %1$s: the action to perform (e.g., "delete"), %2$d: the number of campaigns. */
    _n(
      "Are you sure you want to %1$s %2$d selected campaign?",
      "Are you sure you want to %1$s %2$d selected campaigns?",
      selectedCampaigns.length || 0,
      "campaignbay"
    ),
    bulkActionLabels[bulkAction], // %1$s gets replaced with the action label
    selectedCampaigns.length || 0 // %2$d gets replaced with the number
  );

  const handleCampaignsDelete = async () => {
    // Ensure all campaign IDs are integers
    const campaignsToDeleteInt = selectedCampaigns
      .map((id) => parseInt(id, 10))
      .filter((id) => !isNaN(id));
    setIsLoading(true);
    try {
      await apiFetch({
        path: `/campaignbay/v1/campaigns/bulk`,
        method: "DELETE",
        data: {
          ids: campaignsToDeleteInt,
        },
      });
      addToast(
        _n(
          "Campaign deleted successfully",
          "Campaigns deleted successfully",
          campaignsToDeleteInt.length,
          "campaignbay"
        ),
        "success"
      );
      setSelectedCampaigns([]);
      fetchCampaigns();
    } catch (error) {
      addToast(
        _n(
          "Error deleting campaign",
          "Error deleting campaigns",
          campaignsToDeleteInt.length,
          "campaignbay"
        ),
        "error"
      );
      console.log(error);
    }
  };

  const handleCampaignsStatusUpdate = async () => {
    const campaignsToUpdateInt = selectedCampaigns
      .map((id) => parseInt(id, 10))
      .filter((id) => !isNaN(id));
    setIsLoading(true);
    try {
      await apiFetch({
        path: `/campaignbay/v1/campaigns/bulk`,
        method: "PUT",
        data: {
          ids: campaignsToUpdateInt,
          status:
            bulkAction === "activate" ? "cb_scheduled" : "campaignbay_inactive",
        },
      });
      addToast(
        _n(
          "Campaign status updated successfully",
          "Campaigns status updated successfully",
          campaignsToUpdateInt.length,
          "campaignbay"
        ),
        "success"
      );
      setSelectedCampaigns([]);
      setCampaigns((prev) =>
        prev.map((c) => {
          if (campaignsToUpdateInt.includes(c.id)) {
            return {
              ...c,
              status:
                bulkAction === "activate"
                  ? "cb_scheduled"
                  : "campaignbay_inactive",
            };
          }
          return { ...c };
        })
      );
      setBulkAction("");
      setSelectedCampaigns([]);
    } catch (error) {
      addToast(
        _n(
          "Error updating campaign status",
          "Error updating campaigns status",
          campaignsToUpdateInt.length,
          "campaignbay"
        ),
        "error"
      );
      console.log(error);
    }
  };

  const getCampaignValue = (campaign) => {
    if (campaign.campaign_type === "scheduled") {
      return (
        campaign?.discount_value +
        " " +
        (campaign?.discount_type === "percentage"
          ? "%"
          : woocommerce_currency_symbol)
      );
    }
    const tier = campaign?.campaign_tiers[0];
    return (
      tier?.value +
      " " +
      (tier?.type === "percentage" ? "%" : woocommerce_currency_symbol)
    );
  };

  return (
    <div className="cb-page campaignbay-campaigns">
      <div className="cb-page-header-container">
        <div className="cb-page-header-title">
          {__("Campaigns", "campaignbay")}
        </div>
        <div className="cb-page-header-actions">
          <button
            className="wpab-cb-btn wpab-cb-btn-primary "
            onClick={() => navigate("/campaigns/add")}
          >
            <Icon icon={plus} fill="currentColor" />
            {__("Add Campaign", "campaignbay")}
          </button>
        </div>
      </div>
      <div className="cb-page-container">
        <div className="campaignbay-bg-white">
          {/* ==================================================================== */}
          {/* FILTERS SECTION                                                      */}
          {/* ==================================================================== */}
          <div className="campaignbay-filters">
            <div className="campaignbay-filter-group">
              <div className="campaignbay-filter-group-1">
                <select
                  className="wpab-select"
                  value={bulkAction}
                  onChange={(e) => {
                    handleBulkAction(e.target.value);
                  }}
                >
                  <option value="">Bulk Actions</option>
                  <option value="activate">Activate</option>
                  <option value="deactivate">Deactivate</option>
                  <option value="delete">Delete</option>
                </select>
                <select
                  className="wpab-select"
                  value={statusFilter}
                  onChange={(e) => {
                    setStatusFilter(e.target.value);
                  }}
                >
                  <option value="">Filter by Status</option>
                  <option value="active">Active</option>
                  <option value="scheduled">Scheduled</option>
                  <option value="expired">Expired</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div className="campaignbay-filter-group-2">
                <select
                  className="wpab-select"
                  value={typeFilter}
                  onChange={(e) => setTypeFilter(e.target.value)}
                >
                  <option value="">Filter by Type</option>
                  <option value="scheduled">Schedule Discount</option>
                  <option value="quantity">Quantity Discount</option>
                  <option value="earlybird">EarlyBird Discount</option>
                </select>
                <button
                  className="wpab-cb-btn wpab-cb-btn-primary"
                  onClick={applyFilters}
                >
                  Apply
                </button>
              </div>
            </div>
            <div className="campaignbay-search-box">
              <input
                type="search"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="wpab-input"
                placeholder="Search Campaign"
              />
              <Icon
                icon={search}
                fill="currentColor"
                className="campaignbay-search-icon"
              />
            </div>
          </div>

          {/* ==================================================================== */}
          {/* TABLE CONTAINER                                                    */}
          {/* ==================================================================== */}
          <div className="campaignbay-table-container">
            <table className="campaignbay-table">
              <thead>
                <tr>
                  <th className="campaignbay-table-checkbox-cell">
                    <CbCheckbox
                      checked={isAllSelected}
                      onChange={handleSelectAll}
                    />
                  </th>
                  {tableHeads.map((head) => (
                    <TableHead
                      key={head.value}
                      label={head.label}
                      isSortable={head.isSortable}
                      value={head.value}
                      onClick={() => handleSort(head.value)}
                    />
                  ))}
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  Array.from({ length: itemsPerPage || 10 }).map((_, index) => (
                    <tr key={index}>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton height="24" width="24" borderRadius="full" />
                      </td>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton>
                          <span>August 9, 2025 7:04 pm</span>
                        </Skeleton>
                      </td>
                      <td>
                        <Skeleton>
                          <span>August 9, 2025 7:04 pm</span>
                        </Skeleton>
                      </td>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton />
                      </td>
                      <td>
                        <Skeleton
                          width="24"
                          height="24"
                          className="campaignbay-m-6"
                        />
                      </td>
                    </tr>
                  ))
                ) : campaigns.length === 0 ? (
                  <tr>
                    <td colSpan="10" style={{ textAlign: "center" }}>
                      No campaigns found.
                    </td>
                  </tr>
                ) : (
                  campaigns.map((campaign) => (
                    <tr
                      key={campaign.id}
                      className={
                        selectedCampaigns.includes(campaign.id)
                          ? "is-selected"
                          : ""
                      }
                    >
                      <td className="campaignbay-table-checkbox-cell campaignbay-sticky-l-td">
                        <CbCheckbox
                          checked={selectedCampaigns.includes(campaign.id)}
                          onChange={(isChecked) =>
                            handleSelectCampaign(campaign.id)
                          }
                        />
                      </td>
                      <td>
                        <a
                          className="campaignbay-capitalize "
                          href={`#/campaigns/${campaign.id}`}
                        >
                          {campaign.title}
                        </a>
                      </td>
                      <td>
                        <span
                          className={`campaignbay-status-pill campaignbay-status-${
                            campaign?.status?.replace("campaignbay_", "") || ""
                          }`}
                        >
                          {campaign?.status?.replace("campaignbay_", "") || ""}
                        </span>
                      </td>
                      <td className="campaignbay-capitalize campaignbay-text-secondary">
                        {campaign.campaign_type}
                      </td>
                      <td className="campaignbay-capitalize campaignbay-text-secondary">
                        {getTargetType(campaign.target_type)}
                      </td>
                      <td>{getCampaignValue(campaign)}</td>
                      <td className="campaignbay-text-secondary">
                        {formatDateTime(campaign.start_datetime)}
                      </td>
                      <td className="campaignbay-text-secondary">
                        {formatDateTime(campaign.end_datetime)}
                      </td>
                      <td className="campaignbay-text-secondary">
                        {campaign.usage_count || 0}
                      </td>
                      <td className="campaignbay-text-secondary">
                        {formatDateTime(campaign.modified)}
                      </td>
                      <td className="campaignbay-sticky-r-td">
                        {/* <div className="campaignbay-action-button-container"> */}
                        <ToolbarDropdownMenu
                          icon={moreVertical}
                          label="Actions"
                          position="bottom left"
                          controls={[
                            {
                              title: "Edit",
                              icon: edit,
                              onClick: () =>
                                navigate(`/campaigns/${campaign.id}`),
                            },
                            {
                              title: "Delete",
                              icon: trash,
                              onClick: () => {
                                setSelectedCampaignId(campaign.id);
                                setIsDeleteModalOpen(true);
                              },
                            },
                          ]}
                        />
                        {/* </div> */}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* ==================================================================== */}
          {/* FOOTER SECTION                                                     */}
          {/* ==================================================================== */}
          <div className="campaignbay-table-footer">
            <div className="campaignbay-bulk-actions-footer">
              {/* <CbCheckbox checked={isAllSelected} onChange={handleSelectAll} />
              <span>{selectedCampaigns.length} ITEM SELECTED</span> */}
              Show Campaigns
              <select
                className="wpab-select campaignbay-hidden-border"
                value={itemsPerPage}
                onChange={(e) => {
                  setItemsPerPage(Number(e.target.value));
                  setCurrentPage(1);
                }}
              >
                {[10, 25, 50, 100].map((num) => (
                  <option key={num} value={num}>
                    {num}
                  </option>
                ))}
              </select>
              per page.
            </div>
            <div className="campaignbay-pagination">
              <span>PAGE</span>
              <select
                className="wpab-select campaignbay-hidden-border"
                value={currentPage}
                onChange={(e) => setCurrentPage(e.target.value)}
              >
                {Array.from({ length: totalPages }, (_, index) => (
                  <option key={index + 1} value={index + 1}>
                    {index + 1}
                  </option>
                ))}
              </select>
              <span className="campaignbay-w-max">OF {totalPages}</span>
              <div className="campaignbay-pagination-arrows campaignbay-ml-4">
                <button
                  className="wpab-cb-btn campaignbay-arrow-button"
                  disabled={currentPage === 1}
                  onClick={() => setCurrentPage(currentPage - 1)}
                >
                  <Icon icon={previous} fill="currentColor" />
                </button>
                <button
                  className="wpab-cb-btn campaignbay-arrow-button"
                  disabled={currentPage === parseInt(totalPages)}
                  onClick={() => setCurrentPage(currentPage + 1)}
                >
                  <Icon icon={next} fill="currentColor" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <ConfirmDialog
        isOpen={isDeleteModalOpen}
        onConfirm={handleCampaignDelete}
        onCancel={() => setIsDeleteModalOpen(false)}
      >
        {__("Are you sure you want to delete this campaign?", "campaignbay")}
      </ConfirmDialog>

      <ConfirmDialog
        isOpen={isBulkActionModalOpen}
        onConfirm={handleBulkActionModal}
        onCancel={() => setIsBulkActionModalOpen(false)}
      >
        {selectedCampaigns?.length > 0
          ? confirmationMessage
          : __(
              "Please select campaigns to perform this action.",
              "campaignbay"
            )}
      </ConfirmDialog>
    </div>
  );
};

export default Campaigns;
