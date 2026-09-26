# Rider Issue Photo Design

A rider reporting a delivery issue must choose a reason and upload a dedicated issue photo. The photo is submitted to the failed-attempt endpoint as evidence and must never be submitted as delivery proof.

The existing delivery-proof controls remain separate. Successful issue submission refreshes both shipments and batches so the stale green **Proof submitted** state is not retained.

The rider sees failed-attempt feedback; cancellation remains a dispatcher decision.
