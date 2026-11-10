<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee as the list and the edit form see them.
 *
 * The monthly rate is omitted entirely — not sent as null — for anyone without
 * the salary permission. A key that is absent cannot be read out of the Inertia
 * page source by someone looking at the browser's network tab, which is the
 * difference between hiding a column and not disclosing it (RA 10173).
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'employeeNo' => $this->resource->employee_no,
            'itemNo' => $this->resource->item_no,
            'lastName' => $this->resource->last_name,
            'firstName' => $this->resource->first_name,
            'middleName' => $this->resource->middle_name,
            'suffix' => $this->resource->suffix,

            // Built by the model so the list, the PDF and the Excel export all
            // spell a name the same way.
            'listName' => $this->resource->list_name,
            'fullName' => $this->resource->full_name,

            'positionTitle' => $this->resource->position_title,
            'salaryGrade' => $this->resource->salary_grade,
            'employmentStatus' => $this->resource->employment_status,
            'officeId' => $this->resource->office_id,

            // whenLoaded, so a page that forgot to eager load the office gets a
            // missing name rather than one query per row. Null where the record
            // has no office yet, which is how an imported employee arrives.
            'officeName' => $this->whenLoaded('office', fn () => $this->resource->office?->name),

            'isActive' => $this->resource->is_active,

            // Null for everyone on the masterlist; a date only on the deleted
            // records list, where the row says how long it has been in the bin.
            'deletedAt' => $this->resource->deleted_at?->toIso8601String(),

            'leaveRecordsCount' => $this->when(
                $this->resource->leave_records_count !== null,
                fn (): int => (int) $this->resource->leave_records_count,
            ),
            'monthlyRate' => $this->when(
                $request->user()?->can('viewSalary', Employee::class) ?? false,
                fn (): ?string => $this->resource->monthly_rate,
            ),
        ];
    }
}
