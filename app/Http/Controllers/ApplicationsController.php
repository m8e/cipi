<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Application;
use App\Server;
use App\Alias;
use phpseclib\Net\SSH2 as SSH;
use PDF;

class ApplicationsController extends Controller {

    public function index() {
        $applications = Application::with('server')->with('aliases')->get();
        return view('applications', compact('applications'));
    }

    public function api() {
        return Application::orderBy('domain')->get();
    }

    public function create(Request $request) {
        $this->validate($request, [
            'domain' => 'required',
            'server_id' => 'required',
            'basepath' => 'required',
            'php' => 'required'
        ]);
        if(Application::where('domain', $request->domain)->where('server_id', $request->server_id)->first()) {
            $request->session()->flash('alert-error', 'This domain is already taken on this server');
            return redirect('/applications');
        }
        $aliases = Alias::where('domain', $request->domain)->get();
        foreach($aliases as $alias) {
            if($alias->application->sever->id == $request->server_id) {
                $request->session()->flash('alert-error', 'This domain is already taken on this server');
                return redirect('/applications');
            }
        }
        $server = Server::where('id', $request->server_id)->where('complete', 2)->first();
        if(!$server) {
            return abort(403);
        }
        $user   = 'u'.hash('crc32', (Str::uuid()->toString())).rand(1,9);
        $pass   = sha1(uniqid().microtime().$request->domain);
        $dbpass = sha1(microtime().uniqid().$request->ip);
        $appcode= sha1(uniqid().$request->domain.microtime().$request->server_id);
        $base   = $request->basepath;
        $application = Application::create([
            'domain'        => $request->domain,
            'server_id'     => $request->server_id,
            'username'      => $user,
            'password'      => $pass,
            'dbpass'        => $dbpass,
            'basepath'      => $base,
            'php'           => $request->php,
            'appcode'       => $appcode,
        ]);
        if(!$application) {
            $request->session()->flash('alert-error', 'There was a problem! Retry.');
            return redirect('/applications');
        }
        $ssh = New SSH($server->ip, $server->port);
        if(!$ssh->login($server->username, $server->password)) {
            $request->session()->flash('alert-error', 'There was a problem with server connection.');
            return redirect('/applications');
        }
        $ssh->setTimeout(360);
        $response = $ssh->exec('echo '.$server->password.' | sudo -S sudo sh /cipi/host-add.sh -d '.$request->domain.' -u '.$user.' -p '.$pass.' -dbp '.$dbpass.' -b '.$base.' -a '.$appcode);
        $response = explode('###CIPI###', $response);
        if(strpos($response[1], 'Ok') === false) {
            $request->session()->flash('alert-error', 'There was a problem with server scripts.');
            return redirect('/applications');
        }
        $app = [
            'user'          => $user,
            'pass'          => $pass,
            'dbname'        => $user,
            'dbuser'        => $user,
            'dbpass'        => $dbpass,
            'path'          => $base,
            'php'           => $request->php,
            'domain'        => $request->domain,
            'host'          => $server->ip,
            'port'          => $server->port,
        ];
        return view('application', compact('app','appcode'));
    }

    public function destroy(Request $request) {
        $this->validate($request, [
            'appcode' => 'required',
        ]);
        $application = Application::where('appcode', $request->appcode)->get()->first();
        if(!$application) {
            return abort(403);
        }
        $ssh = New SSH($application->server->ip, $application->server->port);
        if(!$ssh->login($application->server->username, $application->server->password)) {
            $request->session()->flash('alert-error', 'There was a problem with server connection.');
            return redirect('/applications');
        }
        $ssh->setTimeout(60);
        foreach ($application->aliases as $alias) {
            $ssh->exec('echo '.$application->server->password.' | sudo -S unlink /etc/cron.d/certbot_renew_'.$alias->domain.'.crontab');
            $ssh->exec('echo '.$application->server->password.' | sudo -S unlink /cipi/certbot_renew_'.$alias->domain.'.sh');
            $ssh->exec('echo '.$application->server->password.' | sudo -S unlink /etc/nginx/sites-enabled/'.$alias->domain.'.conf');
            $ssh->exec('echo '.$application->server->password.' | sudo -S unlink /etc/nginx/sites-available/'.$alias->domain.'.conf');
        }
        $ssh->exec('echo '.$application->server->password.' | sudo -S sudo sh /cipi/host-del.sh -u '.$application->username.' -d '.$application->domain);
        $application->delete();
        $request->session()->flash('alert-success', 'Application has been removed!');
        return redirect('/applications');
    }

    public function pdf($appcode) {
        $application = Application::where('appcode', $appcode)->first();
        $data = [
            'username'      => $application->username,
            'password'      => $application->password,
            'path'          => $application->basepath,
            'ip'            => $application->server->ip,
            'port'          => $application->server->port,
            'domain'        => $application->domain,
            'dbpass'        => $application->dbpass,
            'php'           => $application->php,
        ];
        $pdf = PDF::loadView('pdf', $data);
        return $pdf->download($application->username.'_'.date('YmdHi').'_'.date('s').'.pdf');
    }

}