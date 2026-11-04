# Excel uploads for data flows

This guide explains how to import Excel (XLSX, XLS, ODS) and CSV files into meta objects using
data flows, and how to test such an import end to end. It starts from an empty setup: target
objects exist, but there is no web service, flow or upload page yet.

## How it works

```
User uploads a file
  -> axenox.ETL.file_upload (file is stored)
  -> File flow (links uploads to a data flow)
  -> Data flow
       -> Step "ExcelApiToDataSheet" (one per sheet/target object)
            reads the file via ExcelBuilder using the OpenAPI schema of the web service
       -> writes rows into the target meta object
```

The mapping from Excel columns to attributes is not configured in the step itself. It lives in the
**OpenAPI definition of a web service** that is linked to the flow. The step finds that definition
through the route (web service flow) between the web service and the flow.

## Prerequisites

- The target meta objects exist (e.g. tables created and the model generated from them).
- The upload dialog uses a `FileList` widget with upload support. This works in the **UI5 facade**.
  In the JEasyUI facade the `FileList` is rendered as a plain table without a file picker or drop zone.

UI captions below are given in English; they may differ depending on the UI language.

## 1. Create the web service

1. Go to **Administration → Data Flows → Web services** and press **New**.
2. Fill in:
   - **App**: your app
   - **Name** and **Alias** (e.g. `my-excel-import`)
   - **Version**: keep the default
   - **Flow direction**: `IN`
   - **Type**: the OpenAPI 3 type
3. Save.
4. **Enable the web service.** New web services are created disabled. Select the row and use the
   On/Off button (or edit it and check **Enabled**). Steps of a disabled web service fail with
   `All webservices matching these filters ... are DISABLED`.

## 2. Write the OpenAPI definition

Double-click the web service to open the **Edit steps** dialog and press **Edit OpenAPI definition**.
(Do not confuse it with **Test API**: that opens Swagger UI for HTTP endpoints and is not needed for
file uploads.)

Each target object gets one schema in `components.schemas`:

```json
{
  "openapi": "3.0.3",
  "info": { "title": "My Excel import", "version": "1.0" },
  "paths": {},
  "components": {
    "schemas": {
      "Orders": {
        "type": "object",
        "x-object-alias": "my.App.ORDER",
        "x-excel-sheet": "Orders",
        "properties": {
          "order_no":   { "type": "string", "x-attribute-alias": "ORDER_NO", "x-excel-column": "Order No" },
          "amount":     { "type": "number", "x-attribute-alias": "AMOUNT", "x-excel-column": "Amount" },
          "order_date": { "type": "string", "format": "date", "x-attribute-alias": "ORDER_DATE", "x-excel-column": "Order date" },
          "active":     { "type": "boolean", "x-attribute-alias": "ACTIVE", "x-excel-column": "Active" }
        }
      }
    }
  }
}
```

Rules:

| Key | Level | Meaning |
|---|---|---|
| `x-object-alias` | schema | Full alias (with namespace) of the target object. Must match exactly. |
| `x-excel-sheet` | schema | Sheet name to read. Omit it for CSV files or to read the active sheet. |
| `x-attribute-alias` | property | Attribute of the target object to fill. |
| `x-excel-column` | property | Header text of the column in the first row of the sheet. |

- The schema name (`Orders` above) is what the step references in `schema_name`.
- Several properties may read the same Excel column (e.g. once as string, once as date).
- The OpenAPI `type`/`format` determines the data type used while reading:
  `string`, `number`, `integer`, `boolean`, `string` + `format: date`, `string` + `format: date-time`.
- A property whose column does not exist in the file is skipped silently, the attribute stays empty.
  Check the header texts carefully.
- Only the sheet named in `x-excel-sheet` is loaded. Formulas referencing other sheets therefore
  evaluate to `#REF!`.

## 3. Create the flow with the wizard

In the **Edit steps** dialog of the web service:

1. **New Route/Flow → Create Excel+JSON import flow**.
2. Enter a **Route** (e.g. `orders-import`) and select the object in **Import to object**.

The wizard creates a flow named `<web service>: <route>`, two steps (`... JSON > App` and
`... Excel > App`) and the route linking the flow to the web service.

Then, right away:

- **Give the new flow an alias.** Go to **Administration → Data Flows**, open the flow and set the
  alias. Flows must be unique per alias and version, and the wizard does not set an alias. A second
  wizard run in the same installation otherwise fails with
  `Duplicate entry '1.0' for key 'etl_flow.Unique per alias and version'`.
- **Delete the JSON step** if you only import files. Disabling it is not enough: disabled steps are
  still loaded when the flow runs, and a step without `to_object` makes the run fail with
  `MetaObjectFactory::createFromString(): Argument #2 ($uidOrAlias) must be of type string, null given`.

## 4. Configure the Excel step

Select the `... Excel > App` step and press **Edit step**:

- **From object**: `axenox.ETL.file_upload`
- **To object**: the target object
- **Prototype**: `axenox/etl/ETLPrototypes/ExcelApiToDataSheet.php`
- **Stop flow on error**: decide whether a failing step should stop the remaining steps

Configuration:

```json
{
  "schema_name": "Orders",
  "validate_api_schema": true,
  "skip_invalid_rows": false,
  "base_data_sheet": {
    "columns": [
      { "attribute_alias": "FLOW_RUN_UID", "formula": "='[#flow_run_uid#]'" }
    ]
  }
}
```

| Option | Default | Notes |
|---|---|---|
| `schema_name` | | Schema in the OpenAPI definition to use. |
| `validate_api_schema` | `true` | Validates the read data against the schema types. Set to `false` if the file intentionally contains values that do not match. |
| `skip_invalid_rows` | `false` | `true` imports row by row and skips failing rows. By default nothing is written if one row fails. |
| `base_data_sheet` | | Adds columns with fixed values or placeholders, e.g. the flow run UID. |

To import several sheets of the same file, add one step per sheet (**Copy step**) and change
`schema_name` and **To object**. Every step of the flow reads the same uploaded file.

## 5. Create the file flow

1. Go to **Administration → Data Flows → File uploads**.
2. In **Available upload flows** press **New**.
3. Fill in name, alias, app, select the **Flow** and check **Enabled**.

A file flow uploads files into exactly one data flow. If XLSX and CSV files need different steps
(e.g. sheet names), create separate flows and file flows for them.

## 6. Upload page

The **File uploads** page of the ETL app can be used as long as it is opened in the UI5 facade.
An app-specific page needs a table on `axenox.ETL.file_upload` with the upload buttons, for example:

```json
{
  "widget_type": "DataTable",
  "object_alias": "axenox.ETL.file_upload",
  "filters": [
    { "attribute_alias": "file_flow" },
    { "attribute_alias": "CREATED_ON", "widget_type": "RangeFilter" }
  ],
  "columns": [
    { "attribute_alias": "file_name" },
    { "attribute_alias": "CREATED_ON" },
    { "attribute_alias": "file_flow__LABEL" },
    { "attribute_alias": "flow_run__status" },
    { "attribute_alias": "flow_run__error_message" }
  ],
  "buttons": [
    { "action": { "alias": "axenox.ETL.FileUploadDialog", "do_not_prefill": true }, "caption": "Upload file", "refresh_input": true },
    { "action_alias": "axenox.ETL.FileUploadProcess", "caption": "Import", "refresh_input": true },
    { "action_alias": "exface.core.DownloadFile" }
  ]
}
```

Add a second table on `axenox.ETL.step_note` filtered by the selected `flow_run` to show the step
results (message, read and written rows).

## 7. Run an import

1. Open the upload dialog, select the file flow and add the file (file picker or drag and drop).
2. **Upload & process** stores the file and runs the flow. **Upload only** just stores it.
3. Select the upload to see the step notes. Each step reports how many rows it read and wrote.
4. To run the flow again for the same file (e.g. after changing the configuration), select the
   upload and use **Import**. The file does not need to be uploaded again.

## Testing an import

Recommendations for checking an import systematically, e.g. after framework or library updates:

- **Test file:** cover the data that tends to break: dates in several formats (including day ≤ 12 to
  detect day/month swaps), date-times, time-only values, long numeric IDs (16+ digits) stored as
  text, values with leading zeros, booleans (`TRUE`/`FALSE`, localized texts like `WAHR`), decimals,
  formulas, merged cells, empty rows and a column that does not exist in the schema. Provide the
  same data as XLSX, ODS and CSV (comma and semicolon/decimal-comma variants) if those formats are used.
- **Target table:** besides the typed columns, store the raw value in a string column. Conversion
  differences (`6` vs `6.0`, `1` vs `TRUE`, `3-Apr-26` vs `3-Apr-2026`) are only visible there.
- **Tag runs:** write `[#flow_run_uid#]` into a column via `base_data_sheet`. Every row can then be
  assigned to its upload, and two runs can be compared with SQL by joining on a business key.
- **Before/after comparison:** import the same files before and after the change, tag the rows of
  each run and compare them with a NULL-safe comparison (MySQL: `NOT (a.col <=> b.col)`).
- **Check the step notes** first: row counts per step show immediately if a sheet, column or schema
  was not found.

## Troubleshooting

| Symptom | Cause / solution |
|---|---|
| `Duplicate entry '1.0' for key 'etl_flow.Unique per alias and version'` when running the wizard | Another flow without alias exists in the same version. Give the existing flow an alias. |
| `MetaObjectFactory::createFromString() ... null given` in `StepGroup.php` | A step of the flow has no **To object**, often the wizard's JSON step. Set it or delete the step. |
| `All webservices matching these filters ... are DISABLED` | Enable the web service. |
| All steps report `Internal error` | Open the step note (double-click) or look up the `exception_log_id` under **Administration → Logs**. |
| Upload dialog has no file picker, dropped files are downloaded by the browser | The page is rendered with JEasyUI. Open it in the UI5 facade. |
| Upload list stays empty | Check the filters (e.g. the creation date range) and the selected file flow. |
| Step succeeds but attributes stay empty | Header text in `x-excel-column` does not match the file, or the sheet name in `x-excel-sheet` is wrong. |
| Step fails on type errors | Values do not match the schema types. Fix the file or set `validate_api_schema: false`. |
| Formula cells contain `#REF!` | The formula references another sheet, which is not loaded. |
| **Test API** shows `Failed to load API definition` | Not relevant for file uploads; Test API is for HTTP endpoints. |
