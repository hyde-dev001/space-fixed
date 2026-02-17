# Complete Repair System Testing Plan
## End-to-End Testing: Phase 1 → Phase 10D

**Test Duration:** ~2-3 hours  
**Prerequisites:** Database seeded with users, shop owners, and repairers  
**Goal:** Validate entire repair workflow from submission to review

---

## Testing Environment Setup (10 min)

### 1. Verify Database State
```powershell
php artisan migrate:status

# Should show these migrations as "Ran":
# - create_repair_requests_table
# - add_chat_fields_to_repair_requests
# - add_high_value_approval_fields
# - add_work_progress_fields
# - create_repair_reviews_table
```

### 2. Verify Test Data Exists
```powershell
php artisan tinker

# Check users
> App\Models\User::count()  # Should have customers

# Check shop owners
> App\Models\ShopOwner::count()  # Should have shops

# Check repairers (employees)
> App\Models\User::whereHas('employee')->count()  # Should have repairers

> exit
```

### 3. Start Development Server
```powershell
# Terminal 1: Frontend
npm run dev

# Terminal 2: PHP Server (if needed)
php artisan serve

# Verify both running without errors
```

---

## Complete Workflow Test (2 hours)

### Phase 1 & 2: Repair Request Submission (15 min)

#### Test 1.1: Customer Creates Repair Request

**Steps:**
1. Login as a customer at `http://localhost/login`
2. Navigate to `/repair-services`
3. Find a repair shop
4. Click "Request Repair"
5. Fill in repair form:
   - Repair Type: "Sole Replacement"
   - Description: "My Nike Air Max sole is worn out and needs replacement"
   - Upload shoe images
   - Estimated amount: ₱1,500
   - Delivery method: Pick "Pickup" or "Delivery"
6. Submit the form

**✅ Expected:**
- Form submits successfully
- Redirects to "My Repairs" page
- New repair appears with status: "New Request" or "Assigned to Repairer"
- Order number generated (REP-YYYYMMDDXX format)

#### Test 1.2: Verify Database Entry
```powershell
php artisan tinker

# Get the latest repair
> $repair = App\Models\RepairRequest::latest()->first();
> $repair->status  # Should be 'new_request' or 'assigned_to_repairer'
> $repair->repair_type
> $repair->description
> $repair->total_cost
> $repair->delivery_method
> $repair->customer_id  # Should match your user ID
> $repair->shop_owner_id  # Should be assigned
> $repair->assigned_repairer_id  # May be assigned (Phase 2 auto-assignment)

# Check if conversation was created (Phase 3)
> $repair->conversation_id  # Should have a value if auto-assigned
> exit
```

**✅ Expected:**
- Record exists with all fields populated
- Customer ID matches logged-in user
- Shop owner assigned correctly
- Repairer may be auto-assigned

---

### Phase 3: Repairer Acceptance & Chat (20 min)  

#### Test 3.1: Repairer Views and Accepts Repair

**Steps:**
1. Logout from customer account
2. Login as a repairer (employee account)
3. Navigate to repairer dashboard or assigned repairs page
4. Find the newly created repair
5. Click "Accept" button

**✅ Expected:**
- Repair status changes to "Repairer Accepted"
- Conversation/chat is automatically created
- Customer can now chat with repairer

#### Test 3.2: Chat Communication

**Steps:**
1. Logout from repairer account
2. Login as the customer
3. Navigate to `/my-repairs`
4. Click "CHAT" button on the accepted repair
5. Send message: "How long will this take?"
6. Logout and login as repairer
7. Navigate to repair chat
8. Reply: "It will take 2-3 days. The sole needs special adhesive."

**✅ Expected:**
- Chat interface opens
- Messages send and appear in conversation
- Both parties can see message history
- Real-time or after refresh messages appear

#### Test 3.3: Verify Conversation in Database
```powershell
php artisan tinker

> $repair = App\Models\RepairRequest::latest()->first();
> $conversation = $repair->conversation;
> $conversation  # Should exist
> $conversation->messages()->count()  # Should be 2 (your messages)
> $conversation->messages()->get()  # View all messages
> exit
```

---

### Phase 4: Customer Confirmation (10 min)

#### Test 4.1: Customer Reviews and Confirms Repair

**Steps:**
1. Login as customer
2. Navigate to `/my-repairs`
3. Find repair with status "Waiting Customer Confirmation"
4. Click "CONFIRM REPAIR" button
5. Review the confirmation dialog
6. Click "Yes, Confirm"

**✅ Expected:**
- SweetAlert confirmation dialog appears
- After confirmation:
  - Status changes to next phase
  - Success message appears
  - Repair moves to "In Progress" or "Owner Approval Pending" (if high-value)

#### Test 4.2: Verify Status Change
```powershell
php artisan tinker

> $repair = App\Models\RepairRequest::latest()->first();
> $repair->status  # Should be 'confirmed' or 'owner_approval_pending'
> $repair->customer_confirmed_at  # Should have timestamp
> exit
```

---

### Phase 6: Shop Owner High-Value Approval (15 min)

**Note:** This phase only applies if repair cost > ₱5,000

#### Test 6.1: Create High-Value Repair

**Steps:**
1. Create new repair with amount > ₱5,000 (e.g., ₱6,500)
2. Follow steps 1-4 above (submission → acceptance → confirmation)

**✅ Expected:**
- After customer confirmation, status becomes "Owner Approval Pending"
- Repair waits for shop owner approval

#### Test 6.2: Shop Owner Approves High-Value Repair

**Steps:**
1. Logout and login as shop owner
2. Navigate to shop owner dashboard
3. Look for "High-Value Repairs Pending Approval" section
4. Find the ₱6,500 repair
5. Review details
6. Click "APPROVE" button

**✅ Expected:**
- Approval modal/dialog appears
- After approval:
  - Status changes to "Owner Approved"
  - Repair proceeds to "In Progress"
  - Customer and repairer notified

#### Test 6.3: Shop Owner Rejects High-Value Repair

**Alternative Test:**
1. Create another high-value repair
2. Shop owner clicks "REJECT" instead
3. Enter rejection reason: "Cost exceeds budget limit"

**✅ Expected:**
- Status changes to "Owner Rejected"
- Rejection reason saved
- Customer can see rejection reason in My Repairs

#### Test 6.4: Verify in Database
```powershell
php artisan tinker

> $repair = App\Models\RepairRequest::where('total_cost', '>', 5000)->latest()->first();
> $repair->requires_owner_approval  # Should be true
> $repair->owner_approval_status  # 'approved' or 'rejected'
> $repair->owner_approved_by  # Shop owner user ID
> $repair->owner_approved_at  # Timestamp
> $repair->owner_rejection_reason  # If rejected
> exit
```

---

### Phase 8: Work Progress Tracking (20 min)

#### Test 8.1: Repairer Updates Work Progress

**Steps:**
1. Login as repairer
2. Navigate to assigned repairs
3. Find repair that's been approved/confirmed
4. Update progress through all stages:

**Stage 1: Work Started**
- Click "START WORK" button
- Status → "Work Started"

**Stage 2: Awaiting Parts**
- Click "AWAITING PARTS" button
- Enter parts details: "Waiting for rubber sole material from supplier"
- Status → "Awaiting Parts"

**Stage 3: Resume Work**
- Click "RESUME WORK" button
- Status → "Work Resumed"

**Stage 4: Work Completed**
- Click "COMPLETE WORK" button
- Upload completion images
- Add notes: "Sole replaced successfully. New adhesive applied."
- Status → "Completed"

**Stage 5: Ready for Pickup**
- Click "MARK READY FOR PICKUP" button
- Status → "Ready for Pickup"

**✅ Expected:**
- Each status change is reflected immediately
- Progress updates saved
- Customer can see current status in My Repairs
- Timestamps recorded for each stage

#### Test 8.2: Verify Progress in Database
```powershell
php artisan tinker

> $repair = App\Models\RepairRequest::latest()->first();
> $repair->status  # Should be 'ready_for_pickup'
> $repair->work_started_at
> $repair->parts_pending_at
> $repair->parts_pending_details
> $repair->work_resumed_at
> $repair->completed_at
> $repair->completion_notes
> $repair->completion_images  # JSON array
> $repair->ready_for_pickup_at
> exit
```

#### Test 8.3: Customer Views Progress

**Steps:**
1. Login as customer
2. Navigate to `/my-repairs`
3. View the repair details
4. Check status display

**✅ Expected:**
- Current status clearly displayed
- Progress timeline visible (if implemented)
- Status color-coded appropriately
- Estimated/actual completion dates shown

---

### Phase 9: Customer Pickup Confirmation (10 min)

#### Test 9.1: Customer Confirms Pickup

**Steps:**
1. Login as customer
2. Navigate to `/my-repairs`
3. Find repair with status "Ready for Pickup"
4. Click "CONFIRM PICKUP" button
5. Read the dialog: "Have you picked up your repaired item?"
6. Click "Yes, I picked it up"

**✅ Expected:**
- Confirmation dialog appears with inspection reminder
- After confirmation:
  - Status changes to "Picked Up" 
  - Success message: "Pickup confirmed! Thank you."
  - Timestamp recorded
  - "REVIEW" button now appears

#### Test 9.2: Verify Pickup in Database
```powershell
php artisan tinker

> $repair = App\Models\RepairRequest::latest()->first();
> $repair->status  # Should be 'picked_up'
> $repair->picked_up_at  # Should have current timestamp
> exit
```

---

### Phase 10D: Reviews & Ratings (30 min)

#### Test 10D.1: Customer Can Review After Pickup

**Steps:**
1. Login as customer (should already be logged in)
2. On `/my-repairs` page
3. Find the picked-up repair
4. Verify "REVIEW" button is visible
5. Click "REVIEW" button

**✅ Expected:**
- Review modal opens
- Eligibility check passes (API call to `/can-review`)
- Modal shows:
  - Star rating selector
  - Review text area
  - Image upload option

#### Test 10D.2: Submit Review

**Steps:**
1. In review modal:
   - Hover over stars (should highlight)
   - Click 5th star (select 5 stars)
   - Message shows: "⭐ Excellent!"
   - Enter review text:
     ```
     Outstanding service! My shoes look brand new. The repairer was very 
     professional and communicated clearly throughout the process. 
     Highly recommend this shop for any shoe repair needs!
     ```
   - Upload 2 images of the repaired shoes
2. Click "Submit Review"

**✅ Expected:**
- Character counter shows current length (e.g., "156/1000")
- Image previews display
- Submit button enabled (was disabled before rating selected)
- API call: POST `/api/customer/repairs/{id}/review`
- Success message: "Review Submitted! Thank you for your feedback!"
- Modal closes
- REVIEW button state changes

#### Test 10D.3: Verify Review in Database
```powershell
php artisan tinker

> $review = App\Models\RepairReview::latest()->first();
> $review->rating  # Should be 5
> $review->review_text  # Should show your text
> $review->review_images  # Array with 2 image paths
> $review->repair_request_id
> $review->user_id  # Customer who wrote review
> $review->shop_owner_id
> $review->repairer_id
> $review->is_verified  # Should be true
> $review->created_at

# Check the repair relationship
> $review->repairRequest->order_number
> $review->user->first_name

> exit
```

#### Test 10D.4: Check Review Images Saved
```powershell
ls storage\app\public\review-images

# Should show uploaded images with generated filenames
```

#### Test 10D.5: Prevent Duplicate Review

**Steps:**
1. On same repair, click "REVIEW" button again

**✅ Expected:**
- SweetAlert shows: "Cannot Review - Already reviewed"
- Modal does not open
- OR if modal opens, submit fails with duplicate error

#### Test 10D.6: View Shop Reviews (Public API)

**Steps:**
1. Get the shop owner ID from database or UI
2. Test API directly:
```powershell
# In browser console or Postman
# GET http://localhost/api/shop-owners/1/reviews
```

**Expected Response:**
```json
{
  "success": true,
  "reviews": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "rating": 5,
        "review_text": "Outstanding service!...",
        "review_images": ["review-images/xxx.jpg", "review-images/yyy.jpg"],
        "user": {
          "first_name": "John",
          "last_name": "Doe"
        },
        "repairer": {
          "first_name": "Mike",
          "last_name": "Smith"
        },
        "shop_response": null,
        "created_at": "2026-02-15T..."
      }
    ]
  },
  "stats": {
    "average_rating": 5.0,
    "total_reviews": 1,
    "rating_distribution": {
      "5": 1
    }
  }
}
```

#### Test 10D.7: Shop Owner Responds to Review

**Steps:**
1. Logout from customer account
2. Login as shop owner
3. Navigate to shop dashboard/reviews section
4. Find the customer's review
5. Click "Respond" or open response form
6. Enter response:
   ```
   Thank you so much for the wonderful review! We're thrilled that 
   you're happy with the repair. We look forward to serving you again!
   ```
7. Submit response

**Alternative (Using API directly):**
```javascript
// In browser console while logged in as shop owner
fetch('/api/reviews/1/respond', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  },
  body: JSON.stringify({
    response: 'Thank you so much for the wonderful review!'
  })
})
.then(r => r.json())
.then(console.log);
```

**✅ Expected:**
- Response saves successfully
- Timestamp recorded
- Response visible to customers viewing reviews

#### Test 10D.8: Verify Shop Response in Database
```powershell
php artisan tinker

> $review = App\Models\RepairReview::find(1);
> $review->shop_response  # Should show response text
> $review->shop_responded_at  # Should have timestamp
> exit
```

---

## Edge Cases & Error Testing (30 min)

---

### Phase 5: Manager Rejection Review (20 min)

**Note:** When a repairer rejects a repair request, a manager must review and decide whether to approve the rejection or override it and reassign to another repairer.

#### Test 5.1: Repairer Rejects Repair

**Steps:**
1. Login as a repairer
2. Navigate to assigned repairs
3. Find a repair that's been assigned
4. Click "REJECT" or "Decline" button
5. Enter rejection reason: "Customer declined high repair cost - estimate ₱4,500"
6. Attach evidence images (if available)
7. Submit rejection

**✅ Expected:**
- Rejection form opens
- Reason field is required
- After submission:
  - Status changes to "Repairer Rejected" or "Pending Manager Review"
  - Rejection saved to database
  - Manager notification triggered

#### Test 5.2: Manager Reviews Rejection (Approve)

**Steps:**
1. Logout from repairer account
2. Login as manager
3. Navigate to `/shop-owner/repair-reject-approval` or manager dashboard
4. Find pending rejection requests section
5. Click on the rejection request (RR-2026-XXXX)
6. Review details:
   - Customer name
   - Service requested
   - Rejection reason
   - Evidence/images
7. Click "APPROVE REJECTION" button
8. Confirm decision

**✅ Expected:**
- Rejection request displays all details
- Approve button available
- After approval:
  - Status changes to "Approved" (rejection confirmed)
  - Repair request status becomes "Rejected" or "Cancelled"
  - Customer notified of rejection
  - Timeline updated with manager approval

#### Test 5.3: Manager Overrides Rejection (Reassign)

**Alternative Test:**
1. Create another repair and have repairer reject it
2. Manager reviews the rejection
3. Click "OVERRIDE & REASSIGN" button
4. Select different repairer from dropdown
5. Add override reason: "Valid repair request, reassigning to available technician"
6. Confirm reassignment

**✅ Expected:**
- Override option available
- Can select new repairer
- After override:
  - Rejection marked as "Rejected" (manager rejected the rejection)
  - Repair reassigned to new repairer
  - Status changes to "Assigned to Repairer"
  - New repairer notified
  - Timeline shows override event

#### Test 5.4: View All Rejection History

**Steps:**
1. Login as manager or shop owner
2. Navigate to `/shop-owner/rejection-history` or click "View History" link
3. Observe the rejection history page

**✅ Expected:**
- Page title: "All Rejection Requests History"
- List shows all rejection requests with:
  - Request number (RR-2026-XXXX)
  - Service name
  - Customer name
  - Ordered by (manager name)
  - Status badge (Pending/Approved/Rejected)
  - Requested date
- Back button to rejection approval page

#### Test 5.5: Filter Rejection History by Status

**Steps:**
1. On rejection history page
2. Click filter buttons:
   - **"All"** - Shows all rejections
   - **"Pending"** - Shows only pending reviews
   - **"Approved"** - Shows manager-approved rejections
   - **"Rejected"** - Shows manager-overridden rejections
3. Verify filtered results

**✅ Expected:**
- Active filter highlighted (blue background)
- List updates to show only matching status
- Count updates accordingly
- Filter state persists during session

#### Test 5.6: Expand/Collapse Timeline

**Steps:**
1. On rejection history page
2. Find a rejection with multiple history events
3. Click on the rejection card header
4. Observe timeline expansion
5. Click again to collapse

**✅ Expected:**
- Initially: Timeline collapsed, only header visible
- After click: Timeline expands with full history
- Arrow icon rotates (down → up)
- Timeline shows events in chronological order:
  - **Submitted** (blue) - Initial submission
  - **Under Review** (yellow) - Manager reviewing
  - **Approved** (green) - Manager approved rejection
  - **Rejected** (red) - Manager rejected the rejection
- Each event shows:
  - Event title and description
  - Changed by (user name)
  - Timestamp
  - Status badge
  - Optional notes

#### Test 5.7: Verify Timeline Accuracy

**Steps:**
1. Create new repair rejection workflow:
   - Repairer rejects at 10:00 AM
   - Manager reviews at 2:00 PM
   - Manager approves at 2:15 PM
2. Check rejection history timeline
3. Verify timestamps match

**✅ Expected:**
- Timeline shows 3 events:
  1. "Request Submitted" - 10:00 AM, by [Repairer Name]
  2. "Under Review" - 2:00 PM, by [Manager Name], with notes
  3. "Request Approved" - 2:15 PM, by [Manager Name]
- Timestamps accurate
- Visual timeline connector between events
- Color-coded status dots

#### Test 5.8: Verify in Database

```powershell
php artisan tinker

# Check rejection record
> $repair = App\Models\RepairRequest::where('status', 'repairer_rejected')->first();
> $repair->rejection_reason
> $repair->rejected_by  # Repairer ID
> $repair->rejected_at

# Check manager decision
> $repair->manager_reviewed_by  # Manager ID
> $repair->manager_review_decision  # 'approved' or 'overridden'
> $repair->manager_review_notes
> $repair->manager_reviewed_at

# If overridden and reassigned
> $repair->assigned_repairer_id  # New repairer
> $repair->reassignment_reason

> exit
```

**✅ Expected:**
- All rejection fields populated
- Manager review data saved
- Timeline events stored
- If reassigned, new repairer assigned

#### Test 5.9: Check Notifications

**Steps:**
1. After manager approves/overrides rejection
2. Check notification panel for:
   - Customer notification (if rejection approved)
   - New repairer notification (if overridden)

**✅ Expected:**
- Customer notified of final decision
- New repairer gets assignment notification
- Notification includes relevant details

---

## Edge Cases & Error Testing (30 min)

### Test E1: Rejection History Edge Cases

#### E1.1: Empty State
**Steps:**
1. Navigate to rejection history with no rejections in system
2. Apply filters

**✅ Expected:**
- Message: "No rejection requests found matching the selected filter"
- No errors in console

#### E1.2: Rejection Without History
**Steps:**
1. Find rejection with no timeline events (edge case)
2. Expand the card

**✅ Expected:**
- Message: "No history available"
- No JavaScript errors

#### E1.3: Long Timeline
**Steps:**
1. Create rejection with many events (>10)
2. Expand timeline

**✅ Expected:**
- All events display vertically
- Proper spacing maintained
- Scrollable if needed
- No layout breaks

### Test E2: Validation Tests

#### E2.1: Review Without Pickup
**Steps:**
1. Create repair but don't mark as picked_up
2. Try to access review endpoint directly
3. Check eligibility API

**✅ Expected:**
- Cannot review
- API returns: "can_review": false, "reason": "Must be picked up"

#### E2.2: Review With No Rating
**Steps:**
1. Open review modal on picked_up repair
2. Enter only text, no stars
3. Click Submit

**✅ Expected:**
- Warning: "Rating Required - Please select a star rating"

#### E2.3: Duplicate Review Prevention
**Steps:**
1. Already submitted review in Test 10D.2
2. Try creating another review for same repair via API

**✅ Expected:**
- Unique constraint violation
- Error: "You have already reviewed this repair"

#### E2.4: Review Text Too Long
**Steps:**
1. Open review modal
2. Try pasting 2000 characters

**✅ Expected:**
- Frontend textarea limits to 1000 chars
- If bypassed, backend validation rejects

#### E2.5: Too Many Images
**Steps:**
1. Open review modal
2. Try uploading 4 images

**✅ Expected:**
- Warning: "You can upload up to 3 images only"
- 4th image not added

#### E2.6: Large Image File
**Steps:**
1. Try uploading image > 2MB

**✅ Expected:**
- Validation error: "must not be greater than 2048 kilobytes"

### Test E3: Unauthorized Access

#### E3.1: Access Other Customer's Repair
**Steps:**
1. Login as Customer A
2. Try to review Customer B's repair

**✅ Expected:**
- 404 or "Repair not found or not eligible for review"

#### E3.2: Shop Owner Response Without Auth
**Steps:**
1. Logout
2. Try accessing `/api/reviews/1/respond`

**✅ Expected:**
- 401 Unauthorized or redirect to login

---

## Complete User Journey Checklist

Test the entire flow end-to-end without interruption:

- [ ] **Phase 1-2**: Customer submits repair request
- [ ] **Phase 2**: System auto-assigns to repairer
- [ ] **Phase 3**: Repairer accepts, conversation created
- [ ] **Phase 3**: Customer and repairer chat
- [ ] **Phase 4**: Customer confirms after discussion
- [ ] **Phase 5**: (If rejected) Repairer rejects repair with reason
- [ ] **Phase 5**: Manager reviews rejection (approve or override)
- [ ] **Phase 5**: Rejection history displays with timeline
- [ ] **Phase 5**: Filter rejections by status
- [ ] **Phase 6**: (If >₱5k) Shop owner approves high-value repair
- [ ] **Phase 8**: Repairer starts work
- [ ] **Phase 8**: Status → Awaiting Parts (with details)
- [ ] **Phase 8**: Status → Work Resumed
- [ ] **Phase 8**: Status → Completed (with images)
- [ ] **Phase 8**: Status → Ready for Pickup
- [ ] **Phase 9**: Customer confirms pickup
- [ ] **Phase 10D**: Customer submits 5-star review with images
- [ ] **Phase 10D**: Shop owner responds to review
- [ ] **Phase 10D**: Review displays correctly with stats

**Time to Complete Full Journey:** ~45-60 minutes

---

## Database Integrity Checks

Run these queries to verify data consistency:

```powershell
php artisan tinker

# 1. Check repair requests with all fields
> App\Models\RepairRequest::with(['customer', 'shopOwner', 'repairer', 'conversation'])->latest()->first();

# 2. Check conversation has messages
> App\Models\Conversation::with('messages')->latest()->first();

# 3. Check reviews with relationships
> App\Models\RepairReview::with(['repairRequest', 'user', 'shopOwner', 'repairer'])->latest()->first();

# 4. Verify high-value approvals
> App\Models\RepairRequest::where('requires_owner_approval', true)->get();

# 5. Check work progress timestamps
> $repair = App\Models\RepairRequest::latest()->first();
> collect([
    'Started' => $repair->work_started_at,
    'Parts Pending' => $repair->parts_pending_at,
    'Resumed' => $repair->work_resumed_at,
    'Completed' => $repair->completed_at,
    'Ready' => $repair->ready_for_pickup_at,
    'Picked Up' => $repair->picked_up_at,
  ])->filter();

# 6. Verify review stats
> $shopId = 1;
> App\Models\RepairReview::forShop($shopId)->visible()->avg('rating');
> App\Models\RepairReview::forShop($shopId)->visible()->count();

> exit
```

---

## Performance Testing

### Test P1: Large Dataset Performance

**Setup:**
```powershell
# Create 50 test repairs
php artisan tinker
> App\Models\RepairRequest::factory()->count(50)->create();

# Create 100 test reviews
> App\Models\RepairReview::factory()->count(100)->create();
> exit
```

**Tests:**
- My Repairs page loads < 1 second
- Shop reviews page loads < 1 second with pagination
- Review submission < 500ms
- API responses < 200ms

### Test P2: Concurrent Users

**Test:**
- Multiple customers submit reviews simultaneously
- No race conditions on unique constraint
- All reviews save correctly

---

## Browser Compatibility Testing

Test on:
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Edge (latest)
- [ ] Safari (if Mac available)

**Features to test:**
- Review modal displays correctly
- Star rating interactive on all browsers
- Image upload works
- SweetAlert dialogs display properly
- No console errors

---

## Mobile Responsiveness (If Applicable)

Test on mobile viewport (F12 → Toggle Device Toolbar):

- [ ] My Repairs page responsive
- [ ] Review modal fits screen
- [ ] Star rating tappable
- [ ] Image upload works on mobile
- [ ] Forms usable on small screens

---

## Final System Health Check

```powershell
# Run all migrations
php artisan migrate:status

# Check for any errors in logs
Get-Content storage\logs\laravel.log -Tail 50

# Verify all routes
php artisan route:list | Select-String "repair"

# Check database connections
php artisan tinker --execute="DB::connection()->getPdo();"

# Test queue (if using)
php artisan queue:work --once

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

---

## Test Results Summary Template

```
=== COMPLETE REPAIR SYSTEM TEST RESULTS ===
Date: February 15, 2026
Tester: [Your Name]

Phase 1-2: Repair Submission        [✓] Pass  [ ] Fail
Phase 3: Chat Integration            [✓] Pass  [ ] Fail
Phase 4: Customer Confirmation       [✓] Pass  [ ] Fail
Phase 5: Manager Review              [✓] Pass  [ ] Fail  [N/A]
Phase 6: Shop Owner Approval         [✓] Pass  [ ] Fail  [N/A]
Phase 8: Work Progress Tracking      [✓] Pass  [ ] Fail
Phase 9: Customer Pickup             [✓] Pass  [ ] Fail
Phase 10D: Reviews & Ratings         [✓] Pass  [ ] Fail

Edge Cases:                          [✓] Pass  [ ] Fail
Database Integrity:                  [✓] Pass  [ ] Fail
Performance:                         [✓] Pass  [ ] Fail
Browser Compatibility:               [✓] Pass  [ ] Fail

Critical Issues Found: [Number]
Minor Issues Found: [Number]

Overall Status: [ ] Ready for Production  [ ] Needs Fixes

Notes:
-
-
-
```

---

## Common Issues & Solutions

### Issue 1: "Table repair_reviews not found"
**Solution:** Run `php artisan migrate`

### Issue 2: "Conversation not created"
**Solution:** Check if repairer was assigned. Run conversation seeder if needed.

### Issue 3: Review modal not opening
**Solution:** 
- Check browser console for errors
- Verify `npm run dev` is running
- Clear browser cache
- Check if status is actually "picked_up"

### Issue 4: Images not uploading
**Solution:**
- Run `php artisan storage:link`
- Check folder permissions on `storage/app/public`
- Verify file size < 2MB

### Issue 5: Unauthorized errors
**Solution:**
- Check if logged in with correct account type
- Verify CSRF token in forms
- Check session middleware

---

## Next Steps After Testing

1. **Document all bugs found** in issue tracker
2. **Prioritize fixes**: Critical → High → Medium → Low
3. **Implement missing features**:
   - Shop profile review display
   - Shop owner response UI in dashboard
4. **Phase 10A-C**: Email/SMS/In-app notifications
5. **User Acceptance Testing** with real users
6. **Staging deployment** for final validation
7. **Production deployment** checklist

---

## Production Deployment Checklist

Before going live:
- [ ] All tests passing
- [ ] No console errors
- [ ] Database backups configured
- [ ] Storage folder writable
- [ ] CDN configured for images (optional)
- [ ] Email notifications configured
- [ ] Error logging enabled
- [ ] Rate limiting configured
- [ ] Security headers set
- [ ] SSL certificate installed
- [ ] Performance optimization done
- [ ] Mobile tested thoroughly
- [ ] User documentation complete

---

**Total Estimated Testing Time: 2-3 hours**

Good luck with your testing! 🚀
