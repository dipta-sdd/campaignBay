import { useState, useEffect, useCallback } from "@wordpress/element";
import { Modal, Spinner, Button, ButtonGroup } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

/**
 * A self-contained modal component for fetching and displaying debug logs.
 *
 * @param {object} props                  Component props.
 * @param {boolean} props.isLogModalOpen         Whether the modal is currently open.
 * @param {Function} props.setIsLogModalOpen      Function to set the modal's open state.
 * @param {boolean} props.isClearingLogs       Whether the clearing action is in progress.
 * @param {Function} props.handleClearLogs      The function to call to clear the logs.
 */
const LogViewerModal = ({
  isLogModalOpen,
  setIsLogModalOpen,
  isClearingLogs,
  handleClearLogs,
}) => {
  const [logContent, setLogContent] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  /**
   * A memoized function to fetch logs from the API.
   * useCallback ensures the function reference doesn't change on every render.
   */
  const fetchLogs = useCallback(async () => {
    setIsLoading(true);
    try {
      const response = await apiFetch({ path: "/campaignbay/v1/logs" });
      setLogContent(response.log_content || "No logs recorded for today.");
    } catch (error) {
      setLogContent(`Error fetching logs: ${error.message}`);
      console.error("Log fetch error:", error);
    }
    setIsLoading(false);
  }, []); // Empty dependency array means this function is created only once.

  // This effect runs whenever the modal is opened.
  useEffect(() => {
    if (isLogModalOpen) {
      fetchLogs();
    }
  }, [isLogModalOpen, fetchLogs]);

  const closeModal = () => {
    setIsLogModalOpen(false);
  };

  /**
   * A new handler that calls the parent's clearing function
   * and then refetches the logs to update the view.
   */
  const onClearLogsClick = async () => {
    // The handleClearLogs function from the parent will show the confirmation.
    const success = await handleClearLogs();

    // If the parent function returns true (indicating success), refetch the logs.
    if (success) {
      fetchLogs();
    }
  };

  if (!isLogModalOpen) {
    return null;
  }

  return (
    <Modal
      title="Today's Debug Log"
      onRequestClose={closeModal}
      className="campaignbay-log-viewer-modal"
    >
      <pre className="campaignbay-log-content">
        {isLoading ? <Spinner /> : logContent}
      </pre>

      {/* Add a footer with the clear button */}
      <div className="campaignbay-log-modal-footer">
        <ButtonGroup>
          <Button
            variant="secondary"
            onClick={fetchLogs}
            isBusy={isLoading} // Show spinner when refreshing
          >
            Refresh
          </Button>
          <Button
            variant="secondary"
            isDestructive
            onClick={onClearLogsClick}
            isBusy={isClearingLogs}
          >
            Clear Log Files
          </Button>
        </ButtonGroup>
      </div>
    </Modal>
  );
};

export default LogViewerModal;
