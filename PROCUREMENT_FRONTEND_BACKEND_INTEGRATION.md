# Procurement Frontend-Backend Integration Guide

## ✅ Completed

### 1. PurchaseOrders.tsx - **FULLY INTEGRATED**
- ✅ Removed all mock data
- ✅ Using `purchaseOrderApi` from `@/services/purchaseOrderApi`
- ✅ Using `purchaseRequestApi` from `@/services/purchaseRequestApi`
- ✅ Using proper TypeScript types from `@/types/procurement`
- ✅ Fetching real data on component mount
- ✅ Real-time metrics from backend
- ✅ Create, update, delete operations connected to API
- ✅ Status progression workflow implemented
- ✅ Cancel order functionality with backend integration

**Key Changes:**
```typescript
// OLD - Mock Data
const initialPurchaseOrders: PurchaseOrderItem[] = [...]

// NEW - Real API
const fetchPurchaseOrders = async () => {
  const response = await purchaseOrderApi.getAll();
  setPurchaseOrders(response.data);
};
```

### 2. PurchaseRequest.tsx - **ALREADY INTEGRATED**
- ✅ Already using `purchaseRequestApi` and `supplierApi`
- ✅ Fetching real data from backend
- ✅ No mock data found
- ✅ Proper error handling in place

---

## 🔧 Remaining Updates Needed

### 3. Replenishment Requests.tsx - **NEEDS INTEGRATION**

**Current Status:** Using mock data

**Required Changes:**

#### Step 1: Update Imports
```typescript
// ADD these imports at the top
import { useEffect } from "react"; // Add useEffect
import { replenishmentRequestApi } from "@/services/replenishmentRequestApi";
import type { ReplenishmentRequest } from "@/types/procurement";
```

#### Step 2: Remove Mock Data
```typescript
// REMOVE this entire const
const replenishmentRows: ReplenishmentRequestItem[] = [...]
```

#### Step 3: Add State Management
```typescript
export default function ReplenishmentRequests() {
  // ADD these state hooks
  const [requests, setRequests] = useState<ReplenishmentRequest[]>([]);
  const [loading, setLoading] = useState(true);
  
  // ADD fetch function
  const fetchRequests = async () => {
    try {
      setLoading(true);
      const response = await replenishmentRequestApi.getAll();
      setRequests(response.data);
    } catch (error) {
      console.error("Failed to fetch replenishment requests:", error);
      Swal.fire("Error", "Failed to load requests", "error");
    } finally {
      setLoading(false);
    }
  };

  // ADD useEffect
  useEffect(() => {
    fetchRequests();
  }, []);
}
```

#### Step 4: Update Badge Classes
```typescript
// CHANGE from Title Case to lowercase
const priorityBadgeClass: Record<string, string> = {
  high: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
  medium: "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
  low: "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300",
};

const statusBadgeClass: Record<string, string> = {
  pending: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
  accepted: "bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300",
  rejected: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
  needs_details: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300",
};
```

#### Step 5: Update Field Names in JSX
```typescript
// CHANGE field access to match backend format
{paginatedItems.map((request) => (
  <tr key={request.id}>
    <td>{request.request_number}</td>  {/* was: requestNo */}
    <td>{request.product_name}</td>     {/* was: productName */}
    <td>{request.sku_code}</td>         {/* was: skuCode */}
    <td>{request.quantity_needed}</td>  {/* was: quantityNeeded */}
    <td>{request.requester?.name}</td>  {/* was: requestedBy */}
    <td>{request.requested_date}</td>   {/* was: requestedDate */}
    // ... etc
  </tr>
))}
```

#### Step 6: Update Action Handlers
```typescript
const handleAccept = async (request: ReplenishmentRequest) => {
  // ... confirmation dialog
  
  try {
    await replenishmentRequestApi.accept(request.id);
    await Swal.fire({
      title: "Accepted",
      text: "Request has been accepted for supplier sourcing.",
      icon: "success",
      timer: 1500,
    });
    fetchRequests(); // Refresh data
  } catch (error) {
    console.error("Failed to accept request:", error);
    Swal.fire("Error", "Failed to accept request", "error");
  }
};

const handleReject = async (request: ReplenishmentRequest) => {
  const { value: notes } = await Swal.fire({
    title: "Reject request?",
    input: "textarea",
    inputLabel: "Rejection Reason",
    inputPlaceholder: "Enter reason...",
    showCancelButton: true,
    inputValidator: (value) => {
      if (!value) return "Please provide a reason";
      return null;
    }
  });

  if (!notes) return;

  try {
    await replenishmentRequestApi.reject(request.id, { rejection_notes: notes });
    await Swal.fire("Rejected", "Request has been rejected.", "success");
    fetchRequests();
  } catch (error) {
    console.error("Failed to reject request:", error);
    Swal.fire("Error", "Failed to reject request", "error");
  }
};
```

---

### 4. StockRequestApproval.tsx - **NEEDS INTEGRATION**

**Current Status:** Using mock data

**Required Changes:**

#### Step 1: Update Imports
```typescript
import { useEffect } from "react";
import { stockRequestApi } from "@/services/stockRequestApi";
import type { StockRequestApproval } from "@/types/procurement";
```

#### Step 2: Remove Mock Data
```typescript
// REMOVE
const stockRequestRows: StockRequestItem[] = [...]
```

#### Step 3: Add State & Fetch Logic
```typescript
export default function StockRequestApproval() {
  const [stockRequests, setStockRequests] = useState<StockRequestApproval[]>([]);
  const [loading, setLoading] = useState(true);
  const [metrics, setMetrics] = useState({ total_requests: 0, pending: 0, accepted: 0 });
  
  const fetchStockRequests = async () => {
    try {
      setLoading(true);
      const response = await stockRequestApi.getAll();
      setStockRequests(response.data);
    } catch (error) {
      console.error("Failed to fetch stock requests:", error);
      Swal.fire("Error", "Failed to load stock requests", "error");
    } finally {
      setLoading(false);
    }
  };

  const fetchMetrics = async () => {
    try {
      const data = await stockRequestApi.getMetrics();
      setMetrics(data);
    } catch (error) {
      console.error("Failed to fetch metrics:", error);
    }
  };

  useEffect(() => {
    fetchStockRequests();
    fetchMetrics();
  }, []);
}
```

#### Step 4: Update Field Names
```typescript
// Similar changes as Replenishment Requests
{paginatedItems.map((request) => (
  <tr key={request.id}>
    <td>{request.request_number}</td>
    <td>{request.product_name}</td>
    <td>{request.sku_code}</td>
    <td>{request.quantity_needed}</td>
    <td>{request.requester?.name}</td>
    // ... etc
  </tr>
))}
```

#### Step 5: Update Action Handlers
```typescript
const handleApprove = async (request: StockRequestApproval) => {
  const result = await Swal.fire({
    title: "Approve request?",
    text: `Approve stock request ${request.request_number}?`,
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Yes, approve",
    confirmButtonColor: "#2563eb",
  });

  if (!result.isConfirmed) return;

  try {
    await stockRequestApi.approve(request.id);
    await Swal.fire("Approved", "Stock request approved.", "success");
    fetchStockRequests();
    fetchMetrics();
  } catch (error) {
    console.error("Failed to approve:", error);
    Swal.fire("Error", "Failed to approve request", "error");
  }
};
```

---

### 5. SuppliersManagement.tsx - **NEEDS INTEGRATION**

**Current Status:** Using mock data

**Required Changes:**

#### Step 1: Update Imports
```typescript
import { useEffect } from "react";
import { supplierApi } from "@/services/supplierApi";
import type { Supplier } from "@/types/procurement";
```

#### Step 2: Remove Mock Data
```typescript
// REMOVE
const supplierRows: SupplierItem[] = [...]
```

#### Step 3: Add State & Fetch Logic
```typescript
export default function SuppliersManagement() {
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loading, setLoading] = useState(true);
  
  const fetchSuppliers = async () => {
    try {
      setLoading(true);
      const response = await supplierApi.getAll();
      setSuppliers(response.data);
    } catch (error) {
      console.error("Failed to fetch suppliers:", error);
      Swal.fire("Error", "Failed to load suppliers", "error");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSuppliers();
  }, []);
}
```

#### Step 4: Update Field Names
```typescript
{paginatedItems.map((supplier) => (
  <tr key={supplier.id}>
    <td>{supplier.name}</td>              {/* was: supplierName */}
    <td>
      {supplier.phone} | {supplier.email} {/* was: contactInfo */}
    </td>
    <td>{supplier.products_supplied}</td>  {/* was: productsSupplied */}
    <td>{supplier.purchase_order_count} orders</td> {/* was: purchaseHistory */}
  </tr>
))}
```

#### Step 5: Update CRUD Operations
```typescript
const handleCreate = async () => {
  if (!formData.name.trim() || !formData.email.trim()) {
    Swal.fire("Warning", "Please fill required fields", "warning");
    return;
  }

  try {
    await supplierApi.create({
      name: formData.name,
      contact_person: formData.contactPerson,
      email: formData.email,
      phone: formData.phone,
      address: formData.address,
      products_supplied: formData.productsSupplied,
    });
    
    await Swal.fire("Success", "Supplier created successfully", "success");
    closeModal();
    fetchSuppliers();
  } catch (error) {
    console.error("Failed to create supplier:", error);
    Swal.fire("Error", "Failed to create supplier", "error");
  }
};

const handleUpdate = async () => {
  if (!editingSupplier) return;

  try {
    await supplierApi.update(editingSupplier.id, {
      name: formData.name,
      contact_person: formData.contactPerson,
      email: formData.email,
      phone: formData.phone,
      address: formData.address,
      products_supplied: formData.productsSupplied,
    });

    await Swal.fire("Success", "Supplier updated successfully", "success");
    closeEditModal();
    fetchSuppliers();
  } catch (error) {
    console.error("Failed to update supplier:", error);
    Swal.fire("Error", "Failed to update supplier", "error");
  }
};

const handleDelete = async (supplierId: number) => {
  const result = await Swal.fire({
    title: "Delete Supplier?",
    text: "This action cannot be undone.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Delete",
    confirmButtonColor: "#dc2626",
  });

  if (!result.isConfirmed) return;

  try {
    await supplierApi.delete(supplierId);
    await Swal.fire("Deleted", "Supplier deleted successfully", "success");
    fetchSuppliers();
  } catch (error) {
    console.error("Failed to delete supplier:", error);
    Swal.fire("Error", "Failed to delete supplier", "error");
  }
};
```

---

## 📋 Backend API Endpoint Checklist

Ensure these endpoints are working:

### Purchase Orders
- ✅ `GET /api/erp/procurement/purchase-orders` - Get all POs
- ✅ `GET /api/erp/procurement/purchase-orders/{id}` - Get single PO
- ✅ `POST /api/erp/procurement/purchase-orders` - Create PO
- ✅ `PUT /api/erp/procurement/purchase-orders/{id}` - Update PO
- ✅ `DELETE /api/erp/procurement/purchase-orders/{id}` - Delete PO
- ✅ `POST /api/erp/procurement/purchase-orders/{id}/update-status` - Update status
- ✅ `POST /api/erp/procurement/purchase-orders/{id}/mark-delivered` - Mark delivered
- ✅ `POST /api/erp/procurement/purchase-orders/{id}/cancel` - Cancel PO
- ✅ `GET /api/erp/procurement/purchase-orders/metrics` - Get metrics

### Purchase Requests
- ✅ `GET /api/erp/procurement/purchase-requests` - Get all PRs
- ✅ `GET /api/erp/procurement/purchase-requests/{id}` - Get single PR
- ✅ `POST /api/erp/procurement/purchase-requests` - Create PR
- ✅ `PUT /api/erp/procurement/purchase-requests/{id}` - Update PR
- ✅ `DELETE /api/erp/procurement/purchase-requests/{id}` - Delete PR
- ✅ `POST /api/erp/procurement/purchase-requests/{id}/submit-to-finance` - Submit
- ✅ `POST /api/erp/procurement/purchase-requests/{id}/approve` - Approve
- ✅ `POST /api/erp/procurement/purchase-requests/{id}/reject` - Reject
- ✅ `GET /api/erp/procurement/purchase-requests/metrics` - Get metrics
- ✅ `GET /api/erp/procurement/purchase-requests/approved` - Get approved PRs

### Replenishment Requests
- ⚠️ `GET /api/erp/procurement/replenishment-requests` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/replenishment-requests/{id}/accept` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/replenishment-requests/{id}/reject` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/replenishment-requests/{id}/request-details` - **NEEDS TESTING**

### Stock Request Approvals
- ⚠️ `GET /api/erp/procurement/stock-requests` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/stock-requests/{id}/approve` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/stock-requests/{id}/reject` - **NEEDS TESTING**
- ⚠️ `GET /api/erp/procurement/stock-requests/metrics` - **NEEDS TESTING**

### Suppliers
- ⚠️ `GET /api/erp/procurement/suppliers` - **NEEDS TESTING**
- ⚠️ `GET /api/erp/procurement/suppliers/{id}` - **NEEDS TESTING**
- ⚠️ `POST /api/erp/procurement/suppliers` - **NEEDS TESTING**
- ⚠️ `PUT /api/erp/procurement/suppliers/{id}` - **NEEDS TESTING**
- ⚠️ `DELETE /api/erp/procurement/suppliers/{id}` - **NEEDS TESTING**

---

## 🧪 Testing Checklist

### For Each Page:
1. ✅ Load page - should show loading state
2. ✅ Data fetches successfully from backend
3. ✅ Search/filter functionality works
4. ✅ Pagination works
5. ✅ Create operation works
6. ✅ Update operation works
7. ✅ Delete operation works (if applicable)
8. ✅ Metrics display correctly
9. ✅ Error handling displays user-friendly messages
10. ✅ Success messages appear after operations

---

## 🚀 Quick Start Integration Steps

1. **Test Backend Endpoints First**
   ```bash
   # Use Postman or Thunder Client to test each endpoint
   # Ensure they return proper JSON responses
   ```

2. **Update Frontend Files in This Order**
   - ✅ PurchaseOrders.tsx (DONE)
   - ✅ PurchaseRequest.tsx (ALREADY DONE)
   - ⚠️ Replenishment Requests.tsx (TODO)
   - ⚠️ StockRequestApproval.tsx (TODO)
   - ⚠️ SuppliersManagement.tsx (TODO)

3. **Test Each Page After Integration**
   - Navigate to the page
   - Check browser console for errors
   - Test all CRUD operations
   - Verify data displays correctly

4. **Common Issues to Watch For**
   - CORS errors - Check `config/cors.php`
   - Authentication errors - Ensure user is logged in
   - Field name mismatches - Backend uses snake_case, not camelCase
   - Type errors - Ensure TypeScript types match backend responses

---

## 📝 Notes

- All API services are already created in `resources/js/services/`
- All TypeScript types are defined in `resources/js/types/procurement.ts`
- The backend implementation plan shows Phase 1 & 2 are complete
- Main task is updating the frontend components to use the APIs instead of mock data

---

## ✅ Summary

**Completed:**
- PurchaseOrders.tsx - Fully integrated with backend
- PurchaseRequest.tsx - Already integrated

**Remaining Work:**
- Replenishment Requests.tsx - Remove mock data, connect to API
- StockRequestApproval.tsx - Remove mock data, connect to API
- SuppliersManagement.tsx - Remove mock data, connect to API

**Estimated Time:** 2-3 hours for all remaining pages
