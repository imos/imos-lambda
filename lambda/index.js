console.log('Loading function');
var fs = require('fs');
var path = require('path');
var exec = require('child_process').exec;

var aws = require('aws-sdk');
var s3 = new aws.S3({apiVersion: '2006-03-01'});

function removeRecursively(directory) {
  var files = fs.readdirSync(directory);
  for (var i = 0; i < files.length; i++) {
    var full_path = path.join(directory, files[i]);
    if (files[i] === '.' || files[i] === '..') {
      continue;
    }

    var stat = fs.statSync(full_path);
    if (stat.isDirectory()) {
      removeRecursively(full_path);
    } else {
      fs.unlinkSync(full_path);
    }
  }
  fs.rmdirSync(directory);
}

function initializeContext(context) {
  context.response = {};
  context.request_id = context.awsRequestId;
  context.response.request_id = context.request_id;
  context.response.start_time = (new Date()).getTime();
  context.callbacks = [];
  context.root = '/tmp/' + context.response.request_id;
  context.is_done = false;

  context.addCallback = function(callback) {
    context.callbacks.push(callback);
  };

  context.onFinalize = function() {
    if (context.is_done) {
      return false;
    }
    context.is_done = true;
    for (var i = context.callbacks.length - 1; 0 <= i; i--) {
      context.callbacks[i](context);
    }
    return true;
  };

  context.onError = function(error) {
    if (!context.onFinalize()) {
      return;
    }
    console.log("Error code: " + error.code + ", error: " + error);
    context.done(error, "lambda");
  };

  context.onSuccess = function(error) {
    if (!context.onFinalize()) {
      return;
    }
    context.response["elapsed_time"] =
        (new Date()).getTime() - context.response.start_time;
    context.succeed(context.response);
  };
}

function initialize(event, context) {
  fs.mkdirSync(context.root);
  context.addCallback(function(context) {
    removeRecursively(context.root);
  });

  fs.mkdirSync(context.root + "/home");
  fs.mkdirSync(context.root + "/tmp");

  var input_file = context.root + "/home/input";
  if ("input" in event && event.input !== null) {
    fs.writeFileSync(input_file, event.input);
  } else {
    try {
      fs.unlinkSync(input_file);
    } catch (e) {}
  }

  return true;
}

function runCommand(event, context, callback) {
  var command = '';
  if ('command' in event && event.command != null && event.command !== '') {
    command = event.command;
  } else {
    if (!fs.existsSync(context.functionName)) {
      context.onError(new Error('event.command is missing.'));
      return;
    }
    command = './' + context.functionName;
  }
  var command =
      "export TMPDIR='" + context.root + "/tmp'; " +
      "export HOME='" + context.root + "/home'; " +
      "export REQUEST_ID='" + context.awsRequestId + "'; " +
      command;
  exec(command, function(error, stdout, stderr) {
    if (error) {
      context.response.code = error.code;
      context.response.signal = error.signal;
      context.response.killed = error.killed;
    }
    context.response.stdout = stdout;
    context.response.stderr = stderr;

    var output_file = context.root + '/home/output';
    if (fs.existsSync(output_file)) {
      context.response.output = 'ephemeral/' + context.request_id;
      s3.putObject(
          {Bucket: event.bucket,
           Key: context.response.output,
           Body: fs.createReadStream(output_file)},
          function(error, data) {
            callback(error);
          });
    } else {
      callback(null);
    }
  });
}

function getObject(event, context, callback) {
  if ("object" in event && event.object !== null && event.object !== "" &&
      "bucket" in event && event.bucket !== null && event.bucket !== "") {
    s3.getObject(
        {Bucket: event.bucket,
         Key: event.object},
        function(error, data) {
          if (!error) {
            fs.writeFileSync(context.root + "/home/object", data.Body);
          }
          callback(error);
        });
  } else {
    callback(null);
  }
}

exports.handler = function(event, context) {
  initializeContext(context);

  try {
    initialize(event, context);
  } catch (e) {
    context.onError(new Error('Initialize error: ' + e));
    return;
  }

  var callback = function(error) {
    if (error) {
      context.onError(error);
    }
    context.onSuccess();
  };

  getObject(event, context, function(error) {
    if (error) {
      callback(error);
      return;
    }
    runCommand(event, context, callback);
  });
};
