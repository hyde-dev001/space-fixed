# Inventory and Procurement Workflow (Current State)

This reflects the post-cutover flow where `stock_request_approvals` is the canonical request backbone, with swimlanes showing actor responsibilities.

```mermaid
flowchart TD
    subgraph System["System / Demand"]
        A["Customer Stock Request\nor Repair Demand"]
    end

    subgraph Procurement["Procurement (Review)"]
        B["Create Stock Request\n(stock_request_approvals)"]
        C{"Procurement\nDecision"}
        D["Status: needs_details"]
        E["Status: rejected"]
        F["Status: accepted"]
    end

    subgraph PRWorkflow["Purchase Request (PR) Flow"]
        G["Create Purchase Request"]
        H["Status: draft"]
        I["Submit to Finance"]
        J["Status: pending_finance"]
    end

    subgraph Finance["Finance (Approval)"]
        K["Review PR"]
        L["Status: pending_shop_owner"]
    end

    subgraph ShopOwner["Shop Owner (Approval)"]
        M{"Shop Owner\nDecision"}
        N["Status: approved"]
        O["Status: rejected"]
    end

    subgraph POWorkflow["Purchase Order (PO) Flow"]
        P["Create PO"]
        Q["Status: draft"]
        R["Send to Supplier"]
        S["Status: sent"]
        T["Status: confirmed"]
        U["Status: in_transit"]
    end

    subgraph Supplier["Supplier / Warehouse"]
        V["Deliver Goods"]
        W["Status: delivered"]
    end

    subgraph Completion["Completion"]
        X["Inventory Updated"]
        Y["Finance Expense Auto-created"]
        Z["Status: completed"]
    end

    A --> B
    B --> C
    C -->|Request Details| D
    D --> B
    C -->|Reject| E
    C -->|Approve| F
    
    F --> G
    G --> H
    H --> I
    I --> J
    
    J --> K
    K --> L
    
    L --> M
    M -->|Approve| N
    M -->|Reject| O
    
    N --> P
    P --> Q
    Q --> R
    R --> S
    S --> T
    T --> U
    
    U --> V
    V --> W
    W --> X
    X --> Y
    Y --> Z
    
    Q -.->|Cancel| Q
    S -.->|Cancel| S
    T -.->|Cancel| T
    U -.->|Cancel| U
```

### Status State Machines

**Stock Request** (Canonical: `/api/erp/procurement/stock-requests`)
```
pending → (needs_details → pending) | accepted | rejected
```

**Purchase Request**
```
draft → pending_finance → pending_shop_owner → approved | rejected
```

**Purchase Order**
```
draft → sent → confirmed → in_transit → delivered → completed
draft | sent | confirmed | in_transit ↝ cancelled
```

## Key Notes

- Canonical request API: `/api/erp/procurement/stock-requests`
- Legacy compatibility API: `/api/erp/procurement/replenishment-requests`
- Legacy `PUT`/`DELETE` on replenishment endpoints return `410 Gone` (deprecated)
- Procurement decision states on stock requests: `pending`, `needs_details`, `accepted`, `rejected`
- PR approval chain: `pending_finance` -> `pending_shop_owner` -> `approved`
- PO delivery updates inventory and auto-creates finance expense
- One-time production data fix checklist for historical size labels: [requested-size-label-normalization-checklist.md](requested-size-label-normalization-checklist.md)
