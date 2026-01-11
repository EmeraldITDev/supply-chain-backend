# Fix Emails Going to Spam - Resend Setup

**Issue:** Test email sent successfully but landed in spam folder  
**Solution:** Improve email authentication and deliverability

---

## ✅ Quick Fixes (Do These First)

### 1. Verify Domain Authentication in Resend

1. **Go to Resend Dashboard:**
   - Visit: https://resend.com/domains
   
2. **Check your domain status:**
   - Should show **green checkmark** ✅
   - If yellow warning ⚠️, DNS records need attention

3. **Click on your domain** to see which records are verified:
   - ✅ SPF Record
   - ✅ DKIM Record  
   - ✅ DMARC Record (optional but helps)

---

## 🌐 Step 2: Add/Verify DNS Records

### Check Current DNS Records

Run these commands in terminal to check what's currently set:

```bash
# Check SPF record
dig TXT yourdomain.com +short

# Check DKIM record (Resend uses this)
dig TXT resend._domainkey.yourdomain.com +short

# Check DMARC record
dig TXT _dmarc.yourdomain.com +short
```

### Required DNS Records

You need **3 DNS records** for proper authentication:

#### Record 1: SPF (Prevents Spoofing)
```
Type: TXT
Name: @ (or leave blank for root domain)
Value: v=spf1 include:amazonses.com ~all
TTL: 3600
```

**What it does:** Tells receiving servers that Resend (via AWS SES) is authorized to send emails on behalf of your domain.

#### Record 2: DKIM (Email Signature)
```
Type: TXT
Name: resend._domainkey
Value: [Get this from Resend dashboard - it's unique to your domain]
TTL: 3600
```

**What it does:** Adds a cryptographic signature to your emails proving they're authentic.

**To get your DKIM value:**
1. Go to https://resend.com/domains
2. Click on your domain
3. Copy the DKIM record value (long string starting with `p=`)

#### Record 3: DMARC (Policy & Reporting)
```
Type: TXT
Name: _dmarc
Value: v=DMARC1; p=quarantine; pct=100; rua=mailto:dmarc@yourdomain.com
TTL: 3600
```

**What it does:** Tells receiving servers what to do if SPF/DKIM checks fail.

---

## 📝 Add DNS Records (Step-by-Step)

### If using Cloudflare:

1. Log into Cloudflare → Select your domain
2. Go to **DNS** → **Records**
3. For each record, click **Add record**:
   - Type: `TXT`
   - Name: (use value from above)
   - Content: (use value from above)
   - Proxy status: **DNS only** (gray cloud icon)
   - TTL: Auto or 3600
4. Click **Save**

### If using GoDaddy:

1. Log into GoDaddy → **My Products** → **DNS**
2. Click **Add** (near TXT Records section)
3. For each record:
   - Type: `TXT`
   - Name: (use value from above)
   - Value: (use value from above)
   - TTL: 600 seconds (default)
4. Click **Save**

### If using Namecheap:

1. Log into Namecheap → **Domain List** → **Manage** → **Advanced DNS**
2. Click **Add New Record**
3. For each record:
   - Type: `TXT Record`
   - Host: (use value from above)
   - Value: (use value from above)
   - TTL: Automatic
4. Click the ✓ checkmark to save

### If using Route53 (AWS):

1. AWS Console → **Route53** → **Hosted Zones** → Select domain
2. Click **Create record**
3. For each record:
   - Record type: `TXT`
   - Record name: (use value from above)
   - Value: (use value from above - in quotes if it has spaces)
   - TTL: 300
4. Click **Create records**

---

## ⏱️ Step 3: Wait for DNS Propagation

After adding DNS records:

1. **Wait 10-30 minutes** (usually enough)
2. **Can take up to 48 hours** for full global propagation
3. **Check propagation status:**
   - Visit: https://dnschecker.org
   - Enter: `resend._domainkey.yourdomain.com`
   - Type: TXT
   - Should show your DKIM record globally

---

## ✉️ Step 4: Improve Email Content

Even with proper DNS, email content matters:

### Current Email Headers (Check These)

The issue might also be in how the email looks. Let's verify:

1. **From Address Must Match Verified Domain:**
   ```env
   # In .env file, make sure:
   MAIL_FROM_ADDRESS=noreply@yourdomain.com
   # NOT: noreply@gmail.com or other domains
   ```

2. **Use Professional Reply-To:**
   Add this to your email templates if needed:
   ```php
   $message->replyTo('support@yourdomain.com');
   ```

### Email Content Best Practices

✅ **DO:**
- Use clear, professional subject lines
- Include unsubscribe links (for marketing emails)
- Use proper HTML structure
- Include plain text alternative
- Keep HTML clean and simple
- Include physical address in footer (for compliance)

❌ **DON'T:**
- Use all caps in subject line
- Use spam trigger words (FREE!, ACT NOW!, etc.)
- Include too many links
- Use URL shorteners
- Send to purchased email lists

---

## 🧪 Step 5: Test Email Deliverability

### Use Mail Tester (Free Tool)

1. **Visit:** https://www.mail-tester.com
2. **Copy the test email address** shown (changes each time)
3. **Send test email via tinker:**
   ```bash
   php artisan tinker
   ```
   ```php
   use App\Services\EmailService;
   $emailService = app(EmailService::class);
   $emailService->sendVendorInvitation('test-xxxxx@mail-tester.com', 'Test Company');
   exit
   ```
4. **Check your score** (aim for 9/10 or 10/10)
5. **Review recommendations** - it will tell you exactly what's wrong

### Check Specific Issues

Mail-tester will show:
- ✅ SPF authentication
- ✅ DKIM signature
- ✅ DMARC policy
- ✅ Spam content score
- ✅ Broken links
- ✅ HTML/CSS issues

---

## 🔄 Step 6: Re-verify in Resend

After adding DNS records:

1. **Go to Resend Dashboard:** https://resend.com/domains
2. **Click "Verify" or "Re-verify"** next to your domain
3. **Wait for green checkmark** on all records
4. **Send another test email**

---

## 🎯 Step 7: Domain Warm-up (Important!)

**Why emails go to spam initially:**
- Your domain has no "sending reputation" yet
- Email providers don't trust new senders
- Need to build trust gradually

**How to warm up your domain:**

1. **Week 1:** Send 5-10 emails per day
2. **Week 2:** Send 20-50 emails per day
3. **Week 3:** Send 50-100 emails per day
4. **Week 4+:** Normal volume

**Best practices during warm-up:**
- Send to engaged recipients first (people who know you)
- Avoid sending to old/purchased lists
- Monitor bounce rates (keep under 5%)
- Respond to replies promptly
- Don't send marketing emails initially

---

## 📊 Step 8: Monitor Deliverability

### In Resend Dashboard:

1. **Go to:** https://resend.com/emails
2. **Check for:**
   - **Delivered** ✅ - Good!
   - **Bounced** ❌ - Bad email address
   - **Complained** ⚠️ - Marked as spam (avoid this!)

### In Your Email Provider:

Check where the email landed:
- ✅ **Inbox** - Perfect!
- ⚠️ **Spam** - Needs work
- ❌ **Not received** - Blocked entirely

---

## 🚨 Common Issues & Solutions

### Issue 1: "SPF validation failed"

**Solution:**
```
Add this DNS record:
Type: TXT
Name: @
Value: v=spf1 include:amazonses.com ~all
```

Wait 30 minutes, test again.

### Issue 2: "DKIM signature missing"

**Solution:**
1. Get DKIM record from Resend dashboard
2. Add as DNS TXT record:
   ```
   Name: resend._domainkey
   Value: [long string from Resend]
   ```
3. Wait for DNS propagation

### Issue 3: "Domain not verified in Resend"

**Solution:**
- Check Resend dashboard
- Ensure all 3 records are added to DNS
- Click "Verify" button
- Wait up to 48 hours for verification

### Issue 4: "Using wrong 'From' address"

**Solution:**
```env
# In .env, must use YOUR verified domain:
MAIL_FROM_ADDRESS=noreply@yourdomain.com

# NOT these:
# MAIL_FROM_ADDRESS=noreply@gmail.com ❌
# MAIL_FROM_ADDRESS=noreply@yahoo.com ❌
# MAIL_FROM_ADDRESS=test@example.com ❌
```

### Issue 5: "Emails still going to spam after DNS setup"

**Solutions:**
1. **Domain warm-up** - Start with small volumes
2. **Content review** - Remove spam trigger words
3. **Contact recipients** - Ask them to mark as "Not Spam"
4. **Whitelist request** - Ask recipients to add you to contacts
5. **Authentication check** - Use mail-tester.com

---

## ✅ Quick Verification Checklist

Before sending more emails, verify:

- [ ] Domain verified in Resend dashboard (green checkmark)
- [ ] SPF record added to DNS
- [ ] DKIM record added to DNS
- [ ] DMARC record added to DNS (optional but recommended)
- [ ] DNS records propagated (check with dnschecker.org)
- [ ] MAIL_FROM_ADDRESS uses verified domain
- [ ] Mail-tester score is 9/10 or 10/10
- [ ] Test email went to inbox (not spam)
- [ ] Resend dashboard shows "Delivered" status

---

## 🎓 Understanding Email Authentication

### SPF (Sender Policy Framework)
- Lists which mail servers can send on your behalf
- Prevents email spoofing
- Required for good deliverability

### DKIM (DomainKeys Identified Mail)
- Adds cryptographic signature to emails
- Proves email wasn't tampered with in transit
- Strongly recommended

### DMARC (Domain-based Message Authentication)
- Tells receivers what to do if SPF/DKIM fail
- Provides reports on email delivery
- Recommended for production

**All three together = Best deliverability** ✅

---

## 📞 Still Having Issues?

### Check Logs:
```bash
tail -f storage/logs/laravel.log | grep "email"
```

### Contact Resend Support:
- Email: support@resend.com
- Dashboard: https://resend.com/support
- They're usually very responsive!

### Advanced Debugging:
```bash
# Check email headers in spam folder
# Look for these headers:
# - X-Spam-Score (should be low)
# - Authentication-Results (should pass)
# - X-Resend-Id (confirms it went through Resend)
```

---

## 🎉 Success Indicators

You'll know it's fixed when:
- ✅ Test emails land in inbox (not spam)
- ✅ Mail-tester score is 9/10 or higher
- ✅ Resend dashboard shows all records verified
- ✅ Recipients receive emails without issues

---

## 📚 Additional Resources

- **Resend Docs:** https://resend.com/docs/send-with-smtp
- **DNS Checker:** https://dnschecker.org
- **Mail Tester:** https://www.mail-tester.com
- **SPF Record Check:** https://mxtoolbox.com/spf.aspx
- **DKIM Validator:** https://dkimvalidator.com

---

**Next Steps:**
1. Add all 3 DNS records
2. Wait 30 minutes
3. Verify in Resend dashboard
4. Test with mail-tester.com
5. Send test email to your inbox

Good luck! 🚀
