import React from "react";
import { createContext, useContext } from "react";

// Create the context
const cbStore = createContext(null);

// Provider component
export const CbStoreProvider = ({ children, value }) => {
  return <cbStore.Provider value={value}>{children}</cbStore.Provider>;
};

// Custom hook for easy access
export const useCbStore = () => {
  const context = useContext(cbStore);
  if (!context) {
    throw new Error("useCbStore must be used within a CbStoreProvider");
  }
  return context;
};

export default cbStore;
