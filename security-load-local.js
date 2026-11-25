import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const base = __ENV.BASE_URL || 'http://127.0.0.1/NursingBasicCourse';
const endpointTime = {
  landing: new Trend('landing_duration', true),
  courses: new Trend('courses_duration', true),
  books: new Trend('books_duration', true),
  instructors: new Trend('instructors_duration', true),
  admin_guard: new Trend('admin_guard_duration', true),
  instructor_guard: new Trend('instructor_guard_duration', true),
  student_guard: new Trend('student_guard_duration', true),
};

export const options = {
  stages: __ENV.PROFILE === 'breakdown' ? [
    { duration: '5s', target: 10 },
    { duration: '15s', target: 25 },
    { duration: '10s', target: 25 },
    { duration: '5s', target: 0 },
  ] : [
    { duration: '15s', target: 10 },
    { duration: '15s', target: 25 },
    { duration: '30s', target: 50 },
    { duration: '10s', target: 50 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<2500'],
  },
  userAgent: 'NBC-Local-ReadOnly-Load/1.0',
};

export default function () {
  http.setResponseCallback(http.expectedStatuses(200, 401));
  const res = http.batch([
    ['GET', `${base}/api/public/landing.php`, null, { tags: { surface: 'landing' } }],
    ['GET', `${base}/api/courses/list.php`, null, { tags: { surface: 'courses' } }],
    ['GET', `${base}/api/books/list.php`, null, { tags: { surface: 'books' } }],
    ['GET', `${base}/api/instructors/list.php`, null, { tags: { surface: 'instructors' } }],
    ['GET', `${base}/api/admin/dashboard.php`, null, { tags: { surface: 'admin-unauth' } }],
    ['GET', `${base}/api/instructor/api.php?a=courses`, null, { tags: { surface: 'instructor-unauth' } }],
    ['GET', `${base}/api/student/api.php?a=notifications`, null, { tags: { surface: 'student-unauth' } }],
  ]);

  check(res[0], { 'public landing responds 200': (r) => r.status === 200 });
  check(res[1], { 'course catalog responds 200': (r) => r.status === 200 });
  check(res[2], { 'book catalog responds 200': (r) => r.status === 200 });
  check(res[3], { 'instructor catalog responds 200': (r) => r.status === 200 });
  check(res[4], { 'admin endpoint rejects anonymous user': (r) => r.status === 401 });
  check(res[5], { 'instructor endpoint rejects anonymous user': (r) => r.status === 401 });
  check(res[6], { 'student endpoint rejects anonymous user': (r) => r.status === 401 });
  ['landing','courses','books','instructors','admin_guard','instructor_guard','student_guard'].forEach((name, index) => endpointTime[name].add(res[index].timings.duration));
  sleep(0.2);
}

export function handleSummary(data) {
  const metrics = data.metrics;
  const result = {
    vus_max: metrics.vus_max?.values?.max ?? null,
    iterations: metrics.iterations?.values?.count ?? null,
    requests: metrics.http_reqs?.values?.count ?? null,
    failed_request_rate: metrics.http_req_failed?.values?.rate ?? null,
    request_duration_ms: metrics.http_req_duration?.values ?? null,
    endpoint_duration_ms: Object.fromEntries(Object.keys(endpointTime).map((name) => [name, metrics[`${name}_duration`]?.values ?? null])),
    checks_passed: metrics.checks?.values?.passes ?? null,
    checks_failed: metrics.checks?.values?.fails ?? null,
  };
  return {
    stdout: JSON.stringify(result, null, 2) + '\n',
    'security-load-summary.json': JSON.stringify(result, null, 2) + '\n',
  };
}
