<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'dob',
        'gender',
        'marital_status',
        'nationality',
        'phone',
        'email',
        'address',
        'profile_picture',
        'job_title',
        'department',
        'employment_type',
        'grade_level',
        'supervisor_name',
        'work_location',
        'hire_date',
        'probation_period',
        'confirmation_date',
        'employment_status',
        'vacation_days',
        'employee_code',
    ];

    /**
     * Get the user associated with this employee.
     */
    public function user()
    {
        return $this->hasOne(User::class, 'employee_id');
    }
}

