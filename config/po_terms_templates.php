<?php

/**
 * Default PO / RFQ terms bodies. Seeded into `po_terms_templates` and used
 * as lazy-create fallbacks in POTermsTemplateController when no DB row exists.
 *
 * Edit the `rfq` block for your organisation’s standard RFQ T&C.
 */
return [
    'goods' => "Standard terms:\n- Deliver only brand-new and compliant goods.\n- Package contents must be clearly identified and accompanied by delivery documents.\n- Replace non-conforming goods at no additional cost.",

    'services' => "Standard terms:\n- Perform services in line with approved scope and timelines.\n- Submit progress evidence with invoice.\n- Rework non-conforming deliverables at no additional cost.",

    'logistics' => "Standard terms:\n- Adhere to agreed pickup and delivery windows.\n- Provide transport documentation and incident reports where applicable.\n- Maintain cargo integrity and compliance throughout transit.",

    'rfq' => <<<'RFQ'
REQUEST FOR QUOTATION — STANDARD TERMS AND CONDITIONS

1. Invitation only. This RFQ is an invitation to quote and does not constitute an offer to contract. No obligation arises until a purchase order is issued by an authorized representative of the company.

2. Quotation validity. Unless a different period is stated in this RFQ, your quotation must remain open for acceptance for at least thirty (30) days from the submission deadline.

3. Completeness and pricing. Prices must be firm, in the currency specified, and inclusive of all charges required to deliver the scope (e.g. packaging, standard freight to the stated delivery point) unless the RFQ explicitly calls out exclusions. Clearly identify any taxes, duties, or government levies.

4. Specifications. Goods and services must conform to the specifications, drawings, samples, or standards referenced in this RFQ and any attached documents. Deviations must be listed explicitly in your quotation.

5. Delivery and performance. Delivery dates and milestones quoted will form part of any resulting order. Late performance may be subject to the remedies stated in the purchase order or applicable law.

6. Confidentiality. Information provided in or with this RFQ is confidential and shall be used only for the purpose of preparing and clarifying your quotation, unless otherwise agreed in writing.

7. Compliance. You warrant that your quotation and performance will comply with all applicable laws and regulations, including health, safety, environmental, export control, and anti-bribery requirements.

8. Evaluation. The company may accept or reject any quotation, waive minor informalities, request clarifications, negotiate with one or more vendors, or cancel this RFQ at any time without liability except as may be required by mandatory law.

9. No compensation. Unless expressly stated otherwise in writing by the company, you bear your own costs of preparing and submitting a quotation.

10. Governing documents. Any purchase order issued shall be governed by its terms, these standard RFQ conditions to the extent not superseded, and any additional written agreement executed by authorized signatories of both parties.
RFQ,
];
